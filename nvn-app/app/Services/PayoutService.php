<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\NotaryProfile;
use App\Models\Payment;
use App\Models\Payout;
use App\Support\AuditLogger;
use App\Support\SettlementMethod;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What each notary is owed, and moving it.
 *
 * The ledger is payments.payout_id. A cleared request fee counts towards a
 * notary's balance for exactly as long as it is unattached; generating a payout
 * attaches its fees, and that attachment is what makes them paid. Nothing is
 * inferred from dates, so a payout run cannot double-pay a fee that an earlier
 * run already covered, however the periods overlap.
 *
 * Attribution follows the same rule as everything else: a fee belongs to the
 * notary the client SELECTED (notary_id), even when the platform notarized on
 * their behalf. Covering a job does not move the money.
 */
class PayoutService
{
    public function __construct(private PaystackService $paystack) {}

    /**
     * Cleared fees for completed jobs that no payout has settled yet.
     *
     * @return Collection<int, Payment>
     */
    public function unpaidPayments(NotaryProfile $profile): Collection
    {
        return Payment::query()
            ->payable()
            ->whereHas('request', fn ($q) => $q
                ->where('notary_id', $profile->id)
                ->where('status', RequestStatus::Completed->value))
            ->with('request')
            ->get();
    }

    /** What the notary would receive if a payout were generated right now. */
    public function owed(NotaryProfile $profile): int
    {
        return $this->unpaidPayments($profile)->sum(
            fn (Payment $payment) => $profile->notaryShare($payment->amount),
        );
    }

    /**
     * Generate a payout per notary with something owed.
     *
     * The platform's own notary is skipped: the platform does not transfer
     * money to itself, and its share of a covered job is commission it already
     * holds.
     *
     * @return SupportCollection<int, Payout>
     */
    public function generateAll(?int $initiatedBy = null): SupportCollection
    {
        $profiles = NotaryProfile::query()
            ->where('is_system_native', false)
            ->with('bankDetails', 'user')
            ->get();

        return $profiles
            ->map(fn (NotaryProfile $profile) => $this->generateFor($profile, $initiatedBy))
            ->filter()
            ->values();
    }

    /** One notary's payout, or null when there is nothing to pay. */
    public function generateFor(NotaryProfile $profile, ?int $initiatedBy = null): ?Payout
    {
        return DB::transaction(function () use ($profile, $initiatedBy) {
            // Lock the rows before claiming them so two concurrent runs cannot
            // both attach the same fee.
            $payments = $this->unpaidPayments($profile)->pluck('id');

            if ($payments->isEmpty()) {
                return null;
            }

            $locked = Payment::whereIn('id', $payments)
                ->whereNull('payout_id')
                ->lockForUpdate()
                ->get();

            if ($locked->isEmpty()) {
                return null;
            }

            $gross      = (int) $locked->sum('amount');
            $share      = (int) $locked->sum(fn (Payment $p) => $profile->notaryShare($p->amount));
            $completed  = $locked->pluck('completed_at')->filter();

            $payout = Payout::create([
                'reference'         => 'PO-' . Str::upper(Str::random(10)),
                'notary_profile_id' => $profile->id,
                'amount'            => $share,
                'commission_amount' => $gross - $share,
                'currency'          => 'NGN',
                'status'            => 'pending',
                'period_start'      => $completed->min()?->toDateString(),
                'period_end'        => $completed->max()?->toDateString(),
                'initiated_by'      => $initiatedBy,
            ]);

            Payment::whereIn('id', $locked->pluck('id'))->update(['payout_id' => $payout->id]);

            AuditLogger::record('payout.generated', 'payout', $payout->id, [
                'notary_profile_id' => $profile->id,
                'amount'            => $share,
                'commission'        => $gross - $share,
                'payments'          => $locked->count(),
            ], $initiatedBy);

            return $payout;
        });
    }

    /**
     * Hand a payout to Paystack.
     *
     * Returns [ok, message]. A rejection is reported rather than thrown: an
     * unfunded balance or transfers not yet enabled is an operational fact for
     * the admin to read, not an exception to swallow.
     */
    public function send(Payout $payout, ?int $initiatedBy = null): array
    {
        if (! $payout->isSendable()) {
            return [false, $this->whyNotSendable($payout)];
        }

        $recipient = $payout->notaryProfile->bankDetails->paystack_recipient_code;
        $name      = $payout->notaryProfile->user?->full_name ?? 'notary';

        // Move it out of "pending" first. If the API call dies mid-flight the
        // row is already claimed, so a retry cannot fire a second transfer for
        // the same reference — Paystack rejects a duplicate reference outright.
        $payout->update(['status' => 'processing', 'failure_reason' => null, 'initiated_by' => $initiatedBy]);

        try {
            $result = $this->paystack->initiateTransfer(
                $payout->amount,
                $recipient,
                $payout->reference,
                'Naija Virtual Notary payout ' . $payout->reference . ' — ' . $name,
            );
        } catch (\Throwable $e) {
            // Unknown outcome: the request may or may not have reached Paystack.
            // Leave it processing and let the webhook or a reconcile settle it,
            // rather than releasing fees that might already be on their way.
            $payout->update(['failure_reason' => 'Could not reach Paystack: ' . $e->getMessage()]);

            AuditLogger::record('payout.send_errored', 'payout', $payout->id, [
                'error' => $e->getMessage(),
            ], $initiatedBy);

            return [false, 'Could not reach Paystack. The payout is left as processing — check it before retrying.'];
        }

        if (! $result['ok']) {
            $this->fail($payout, $result['message']);

            return [false, $result['message']];
        }

        $data = $result['data'];

        $payout->update([
            'paystack_transfer_code' => $data['transfer_code'] ?? null,
            // 'success' is only returned when OTP is off; otherwise the transfer
            // webhook is what confirms it.
            'status'                 => ($data['status'] ?? null) === 'success' ? 'paid' : 'processing',
            'processed_at'           => ($data['status'] ?? null) === 'success' ? now() : null,
        ]);

        AuditLogger::record('payout.sent', 'payout', $payout->id, [
            'transfer_code' => $data['transfer_code'] ?? null,
            'status'        => $data['status'] ?? null,
            'amount'        => $payout->amount,
        ], $initiatedBy);

        return [true, match ($data['status'] ?? null) {
            'success' => 'Transfer completed.',
            'otp'     => 'Paystack sent an OTP to confirm this transfer. Approve it on your Paystack dashboard.',
            default   => 'Transfer submitted. It will show as paid once Paystack confirms it.',
        }];
    }

    /**
     * Settle a payout the platform paid by hand.
     *
     * The fees stay attached exactly as they would after a Paystack transfer —
     * the notary has been paid, and how the money travelled changes nothing
     * about the ledger. What it does change is the evidence: there is no webhook
     * behind this, so the method, their transfer reference and the admin who
     * recorded it are the record.
     */
    public function settleOffline(Payout $payout, array $details, ?int $actorId = null): array
    {
        if (! $payout->isSettleable()) {
            return [false, $payout->isPaid()
                ? 'This payout has already been paid.'
                : 'There is nothing to pay.'];
        }

        $method = (string) ($details['method'] ?? 'bank_transfer');

        $payout->update([
            'status'               => 'paid',
            'processed_at'         => $details['paid_at'] ?? now(),
            'failure_reason'       => null,
            'initiated_by'         => $actorId,
            'settlement_method'    => SettlementMethod::exists($method) ? $method : 'other',
            'settlement_reference' => $details['reference'] ?? null,
            'settlement_note'      => $details['note'] ?? null,
        ]);

        AuditLogger::record('payout.settled_offline', 'payout', $payout->id, [
            'method'    => $payout->settlement_method,
            'amount'    => $payout->amount,
            'their_ref' => $payout->settlement_reference,
        ], $actorId);

        return [true, 'Recorded as paid by ' . Str::lower(SettlementMethod::label($payout->settlement_method)) . '.'];
    }

    /** Called by the transfer webhook. */
    public function markPaid(string $transferCodeOrReference): void
    {
        $payout = $this->locate($transferCodeOrReference);

        if (! $payout) {
            return;
        }

        // Paystack says it landed, and someone had already paid it by hand: the
        // notary has very likely been paid twice. Nothing here can undo either
        // leg, but silence would be the worst outcome — so it is recorded loudly
        // and the payout carries the warning where an admin will see it.
        if ($payout->isOffline()) {
            $payout->update([
                'failure_reason' => 'Paystack also completed a transfer for this payout, which was already '
                    . 'recorded as paid by ' . Str::lower(SettlementMethod::label($payout->settlement_method))
                    . '. The notary may have been paid twice — check before the next payout run.',
            ]);

            AuditLogger::record('payout.double_settlement_suspected', 'payout', $payout->id, [
                'settlement_method' => $payout->settlement_method,
                'amount'            => $payout->amount,
            ]);

            return;
        }

        if ($payout->isPaid()) {
            return;
        }

        $payout->update(['status' => 'paid', 'processed_at' => now(), 'failure_reason' => null]);

        AuditLogger::record('payout.paid', 'payout', $payout->id, ['amount' => $payout->amount]);
    }

    /** Called by the transfer webhook for a failure or a reversal. */
    public function markFailed(string $transferCodeOrReference, string $reason): void
    {
        $payout = $this->locate($transferCodeOrReference);

        if (! $payout) {
            return;
        }

        $this->fail($payout, $reason);
    }

    /**
     * Release the fees so they return to the owed pile, and record why.
     *
     * Without the release a failed transfer would silently erase what the
     * notary is owed — the fees would stay attached to a payout that never paid.
     */
    private function fail(Payout $payout, string $reason): void
    {
        // A payout someone settled by hand is outside Paystack's authority.
        //
        // The dangerous sequence is real: a transfer is submitted, it sits in
        // "processing", an admin gives up and pays the notary from their bank
        // app, and only then does transfer.failed arrive. Releasing the fees on
        // that signal would put money the notary has already received back into
        // "owed", and the next payout run would pay it a second time.
        //
        // So the human's fact stands and the conflict is written down instead.
        // If the transfer really did also land, that is a reconciliation for a
        // person to do with two bank statements, not something to guess at here.
        if ($payout->isOffline()) {
            $payout->update([
                'failure_reason' => Str::limit(
                    'Paystack reported: ' . $reason . ' — but this payout was already recorded as paid by '
                    . Str::lower(SettlementMethod::label($payout->settlement_method))
                    . '. The fees were NOT released. Check both records.', 500),
            ]);

            AuditLogger::record('payout.transfer_failed_after_manual_settlement', 'payout', $payout->id, [
                'reason'            => $reason,
                'settlement_method' => $payout->settlement_method,
                'released_payments' => false,
            ]);

            return;
        }

        DB::transaction(function () use ($payout, $reason) {
            $payout->payments()->update(['payout_id' => null]);

            $payout->update([
                'status'         => 'failed',
                'failure_reason' => Str::limit($reason, 500),
                'processed_at'   => now(),
            ]);
        });

        AuditLogger::record('payout.failed', 'payout', $payout->id, [
            'reason' => $reason,
            'released_payments' => true,
        ]);
    }

    private function locate(string $key): ?Payout
    {
        return Payout::where('paystack_transfer_code', $key)
            ->orWhere('reference', $key)
            ->first();
    }

    private function whyNotSendable(Payout $payout): string
    {
        return match (true) {
            ! Settings::paystackTransfersEnabled()
                => 'Automatic Paystack payouts are switched off. Pay this one yourself and use '
                 . '"Record as paid", or turn transfers on in Platform settings.',
            $payout->isPaid()       => 'This payout has already been paid.',
            $payout->isProcessing() => 'This payout is already with Paystack.',
            $payout->amount <= 0    => 'There is nothing to pay.',
            $payout->notaryProfile?->bankDetails === null
                => 'This notary has no payout account on file.',
            ! $payout->notaryProfile->bankDetails->isVerified()
                => 'This notary\'s account has not been verified with their bank yet.',
            default => 'This notary has no Paystack recipient — re-save their payout account to create one.',
        };
    }
}
