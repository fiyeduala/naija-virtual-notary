<?php

namespace App\Services;

use App\Http\Controllers\Notary\OnboardingFeeController;
use App\Models\NotarizationRequest;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Support\AuditLogger;
use App\Support\SettlementMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Money received outside Paystack.
 *
 * Plenty of clients will pay a Nigerian company by direct bank transfer rather
 * than a checkout page, and a notary may hand over the onboarding fee the same
 * way. Before this the platform had no way to say so: the request sat unpaid
 * with the money already in the account, and the only workaround was to edit a
 * status field and lose every trace of what actually happened.
 *
 * A recorded payment is a claim by a named admin, not a webhook, so it carries
 * who recorded it, how, when and against what reference — and then runs the
 * ordinary settlement path so nothing downstream can tell the difference.
 */
class OfflinePaymentService
{
    /**
     * How recent an abandoned membership checkout has to be before a recorded
     * transfer is treated as the same payment rather than a new one.
     */
    private const REUSE_WINDOW_DAYS = 30;

    public function __construct(
        private RequestFulfillmentService $fulfillment,
        private OffsiteNotarizationService $offsite,
    ) {}

    /**
     * Record a request fee that arrived outside Paystack.
     *
     * $details: method, reference (their transfer ref), note, received_at, amount.
     *
     * Handles an offsite job as well, and has to: bank transfer is how most
     * money on this platform actually arrives, and a notary who transfers their
     * sealing fee would otherwise be unrecordable. Every reference to the fee
     * type below goes through $request->feeType(), so an offsite fee is written
     * as 'offsite_fee' and stays outside scopePayable() — writing it as a
     * request fee would put it in the payout run and hand the notary back a
     * share of the money they just paid us.
     */
    public function recordRequestFee(NotarizationRequest $request, array $details, ?int $actorId = null): Payment
    {
        return DB::transaction(function () use ($request, $details, $actorId) {
            $type = $request->feeType();
            // Paid in full — hand back the payment that exists and change
            // nothing. A second successful row for a fee already covered would
            // double what the notary is owed and what the ledger says the client
            // paid. If money really did arrive twice, that is a refund
            // conversation, not another payment.
            //
            // A request that is only PART paid is a different case and does not
            // stop here. Someone who pays for one document and sends the balance
            // by transfer has to be recordable, or the only way to close the gap
            // is to edit rows by hand.
            if ($request->isFullyPaid() && ($paid = $this->settledFee($request))) {
                return $paid;
            }

            // Reuse the row the client abandoned at checkout if there is one:
            // two payment rows for one attempt would double what the notary is
            // owed. A successful row is never reused — that is money that
            // arrived, and this one is money on top of it.
            $payment = $request->payments()
                ->where('type', $type)
                ->whereIn('status', ['pending', 'failed'])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            // What is still owed, not the whole fee: on a top-up the earlier
            // payment already covers part of it, and charging the full figure
            // again is exactly the double-count this method exists to avoid.
            // An admin who types an amount still wins, since a bank transfer can
            // arrive short, rounded, or with the bank's charges taken out.
            $amount = (int) ($details['amount'] ?? $request->balanceMinor());

            $attributes = $this->settlementAttributes($details, $actorId) + [
                'amount'   => $amount,
                'currency' => $request->currency,
            ];

            if ($payment) {
                $payment->update($attributes);
            } else {
                $payment = Payment::create($attributes + [
                    'request_id'         => $request->id,
                    // On an offsite job this is the notary — they are the
                    // platform's customer here. See OffsiteNotarizationService.
                    'user_id'            => $request->client_id,
                    'type'               => $type,
                    'status'             => 'pending',
                    'paystack_reference' => $this->reference(),
                ]);
            }

            $this->audit($payment, 'payment.recorded_offline', $actorId, [
                'request_id' => $request->id,
                'type'       => $type,
            ]);

            // Same path as the webhook, whichever kind of fee this is: for a
            // marketplace request that notifies the notary and starts the
            // clock, for an offsite job it unlocks the editor. Neither is
            // reachable from the other's settle().
            if ($request->is_offsite) {
                $this->offsite->settle($payment);
            } else {
                $this->fulfillment->settle($payment);
            }

            return $payment->fresh();
        });
    }

    /**
     * The most recent request fee that has already cleared for this job, if any.
     *
     * Both the guard above and the admin form ask this same question — the form
     * so it can refuse with an explanation instead of a false success, the guard
     * so two admins with the modal open at once cannot get past it. Neither
     * treats it as "the fee is settled" on its own: with part payments allowed,
     * that question is NotarizationRequest::isFullyPaid().
     */
    public function settledFee(NotarizationRequest $request): ?Payment
    {
        return $request->payments()
            ->where('type', $request->feeType())
            ->where('status', 'successful')
            ->latest('id')
            ->first();
    }

    /**
     * Record a partner membership fee for a notary who never opened checkout.
     *
     * recordOnboardingFee() below needs a Payment row to settle, and one only
     * exists if the notary pressed "Pay with Paystack" and abandoned it. A
     * partner who simply transfers their yearly fee — which is most of them —
     * leaves no such row, so there is nothing for an admin to click. This makes
     * the row and settles it in one go, which is the same thing the checkout
     * page does, minus Paystack.
     */
    public function recordMembershipFee(\App\Models\User $user, array $details, ?int $actorId = null): Payment
    {
        return DB::transaction(function () use ($user, $details, $actorId) {
            // Reuse a recently abandoned attempt — the notary opened checkout,
            // thought better of it and sent a transfer instead, which is one
            // payment, not two. Older rows are left alone: a pending row from
            // last year's signup is a different event, and settling it now would
            // date this year's fee to then. A successful row is never touched at
            // all; that is a year already bought, and this one buys another.
            $payment = Payment::where('user_id', $user->id)
                ->where('type', 'onboarding_fee')
                ->whereIn('status', ['pending', 'failed'])
                ->where('created_at', '>=', now()->subDays(self::REUSE_WINDOW_DAYS))
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $amount = (int) ($details['amount'] ?? PlatformSetting::get(
                'onboarding_fee_ngn',
                config('nvn.onboarding_fee_ngn'),
            ));

            if (! $payment) {
                $payment = Payment::create([
                    'user_id'            => $user->id,
                    'type'               => 'onboarding_fee',
                    'status'             => 'pending',
                    'currency'           => 'NGN',
                    'amount'             => $amount,
                    'paystack_reference' => $this->reference(),
                ]);
            } else {
                $payment->update(['amount' => $amount, 'currency' => 'NGN']);
            }

            return $this->recordOnboardingFee($payment, $details, $actorId);
        });
    }

    /** Record an onboarding fee that arrived outside Paystack. */
    public function recordOnboardingFee(Payment $payment, array $details, ?int $actorId = null): Payment
    {
        return DB::transaction(function () use ($payment, $details, $actorId) {
            $payment->update($this->settlementAttributes($details, $actorId));

            $this->audit($payment, 'payment.recorded_offline', $actorId);

            OnboardingFeeController::settle($payment);

            return $payment->fresh();
        });
    }

    /** Settle a payment row that already exists and is still waiting. */
    public function recordExisting(Payment $payment, array $details, ?int $actorId = null): Payment
    {
        // A payment that already cleared is left exactly as it is. Otherwise a
        // stale form — or a second admin on the same row — would restamp a
        // Paystack payment as having been handled by hand.
        if ($payment->status === 'successful') {
            return $payment;
        }

        return $payment->type === 'onboarding_fee'
            ? $this->recordOnboardingFee($payment, $details, $actorId)
            : DB::transaction(function () use ($payment, $details, $actorId) {
                $payment->update($this->settlementAttributes($details, $actorId));

                $this->audit($payment, 'payment.recorded_offline', $actorId);

                $this->fulfillment->settle($payment);

                return $payment->fresh();
            });
    }

    /**
     * Undo a mistaken record.
     *
     * Only ever available on a payment the platform recorded itself — a Paystack
     * payment is Paystack's fact and cannot be talked out of by an admin. The
     * request is NOT rewound: the notary has already been told and may have done
     * the work, and quietly pulling a job out from under them is worse than an
     * admin having to speak to someone.
     */
    public function reverse(Payment $payment, string $reason, ?int $actorId = null): bool
    {
        if ($payment->settlement_method === null) {
            return false;
        }

        // Once a fee is inside a payout it is no longer just a payment — it is
        // part of a figure the notary has been paid, or is about to be. Marking
        // it failed here would leave the payout claiming a fee that no longer
        // counts, and nothing would ever reconcile the difference. Cancel or
        // regenerate the payout first; then the fee is loose and can be undone.
        if ($payment->payout_id !== null) {
            return false;
        }

        $payment->update([
            'status'          => 'failed',
            'settlement_note' => trim($payment->settlement_note . "\n\nReversed: " . $reason),
        ]);

        $this->audit($payment, 'payment.offline_reversed', $actorId, ['reason' => $reason]);

        return true;
    }

    /** @return array<string, mixed> */
    private function settlementAttributes(array $details, ?int $actorId): array
    {
        $method = (string) ($details['method'] ?? 'bank_transfer');

        return [
            'settlement_method'    => SettlementMethod::exists($method) ? $method : 'other',
            'settlement_reference' => $details['reference'] ?? null,
            'settlement_note'      => $details['note'] ?? null,
            'recorded_by'          => $actorId,
            // The date the money actually arrived, which is often not today —
            // and it is what the payout period is built from, so it matters.
            'completed_at'         => $details['received_at'] ?? now(),
        ];
    }

    private function audit(Payment $payment, string $action, ?int $actorId, array $extra = []): void
    {
        AuditLogger::record($action, 'payment', $payment->id, $extra + [
            'method'    => $payment->settlement_method,
            'amount'    => $payment->amount,
            'currency'  => $payment->currency,
            'their_ref' => $payment->settlement_reference,
        ], $actorId);
    }

    /**
     * A lookup key for a payment that never went near Paystack.
     *
     * It lives in paystack_reference because that column is what every existing
     * lookup uses; the OFF- prefix keeps it obvious that no Paystack transaction
     * will ever be found behind it.
     */
    private function reference(): string
    {
        return 'OFF-' . Str::upper(Str::random(12));
    }
}
