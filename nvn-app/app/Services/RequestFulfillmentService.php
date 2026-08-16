<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\NotarizationRequest;
use App\Models\NotaryProfile;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\Admin\RequestPaidNotification;
use App\Notifications\FallbackAssignedNotification;
use App\Notifications\NotaryNewRequestNotification;
use App\Notifications\RequestAcceptedNotification;
use App\Support\AdminAlert;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Central place where the payment-first rule and the fallback logic live.
 *
 * - markPaid(): the ONLY path that flips a request to paid, notifies the notary
 *   for the first time, and starts the response clock. Idempotent.
 * - accept() / decline(): notary responses.
 * - takeOver(): the platform picks up a request on the assigned notary's behalf.
 *
 * The response window is an SLA clock, not a lock. Nothing is transferred when
 * it elapses — the admin can take over a paid request at any moment, and does so
 * under the assigned notary's name, price and seal.
 */
class RequestFulfillmentService
{
    /**
     * Confirm payment for a request fee. Idempotent — safe to call from both the
     * Paystack callback and the webhook.
     */
    public function markPaid(string $reference): void
    {
        $payment = Payment::where('paystack_reference', $reference)
            ->where('type', 'request_fee')
            ->first();

        if ($payment) {
            $this->settle($payment);
        }
    }

    /**
     * Clear a request fee, whatever cleared it.
     *
     * Paystack's webhook and an admin recording a bank transfer both land here,
     * so a client who paid into the company account gets the identical
     * treatment: the notary is notified, the clock starts, the audit entry is
     * written. Anything that lived only in the webhook path would silently not
     * happen for offline payments, which is the bug this shape exists to avoid.
     *
     * Idempotent — safe to call from the callback and the webhook both.
     */
    public function settle(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment = Payment::where('id', $payment->id)->lockForUpdate()->first();

            if (! $payment || $payment->status === 'successful') {
                return; // unknown or already processed
            }

            $reference = $payment->paystack_reference;

            $payment->update([
                'status'       => 'successful',
                'completed_at' => $payment->completed_at ?? now(),
            ]);

            $request = $payment->request()->lockForUpdate()->first();

            // A payment row with no request is data corruption, not a state to
            // recover from — bail rather than fatal on the update below. The
            // old guard fell through to $request->update() when this happened.
            if (! $request) {
                return;
            }

            // Only Draft/Submitted moves to Paid. Anything further along has
            // already been paid for and possibly accepted or notarized; the
            // previous guard only short-circuited on Paid and would have reset
            // an in-progress request back to the start.
            if (! in_array($request->status, [RequestStatus::Draft, RequestStatus::Submitted], true)) {
                return;
            }

            $minutes = Settings::fallbackMinutes();

            $request->update([
                'status'             => RequestStatus::Paid,
                'paid_at'            => now(),
                'notary_notified_at' => now(),
                'fallback_due_at'    => now()->addMinutes($minutes),
            ]);

            AuditLogger::record('request.paid', 'notarization_request', $request->id, [
                'reference' => $reference,
                'settled'   => $payment->settlement_method ?? 'paystack',
            ], $request->client_id);

            // Sent before the branch below, so it fires whether the request goes
            // to a partner or straight to the admin desk. This is the revenue
            // event, so it is the one admin alert that also gets an email.
            AdminAlert::send(new RequestPaidNotification($request, $payment));

            // The client picked the platform's own notary straight from the
            // marketplace. There is no partner to wait on, so put it on the admin
            // desk immediately rather than leaving it sitting on a clock.
            if ($request->notary?->is_system_native) {
                $this->assignToAdmin($request, 'system_native_selected', wasFallback: false);

                return;
            }

            // FIRST notification to the notary — never before payment clears.
            $notaryUser = $request->notary?->user;
            if ($notaryUser) {
                $notaryUser->notify(new NotaryNewRequestNotification($request));
                AuditLogger::record('notary.notified', 'notarization_request', $request->id, [
                    'notary_user_id' => $notaryUser->id,
                ], $request->client_id);
            }
        });
    }

    /** Notary accepts a paid request → session is locked in, fallback disarmed. */
    public function accept(NotarizationRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $request->update([
                'status'          => RequestStatus::Accepted,
                'accepted_at'     => now(),
                'fallback_due_at' => null, // disarm the timer
            ]);

            // Promote the session to scheduled-confirmed (already scheduled in Phase 4)
            $request->session?->update(['status' => 'scheduled']);

            AuditLogger::record('request.accepted', 'notarization_request', $request->id, [], $request->notary?->user_id);

            $request->client->notify(new RequestAcceptedNotification($request));
        });
    }

    /** Notary explicitly declines → the platform picks it up straight away. */
    public function decline(NotarizationRequest $request, ?string $reason = null): void
    {
        AuditLogger::record('request.declined', 'notarization_request', $request->id, [
            'reason' => $reason,
        ], $request->notary?->user_id);

        $this->takeOver($request, 'declined');
    }

    /**
     * The platform takes a request over on the assigned notary's behalf.
     *
     * Called by a decline, and by the admin choosing to act on any paid or
     * in-progress request — there is no waiting period. Nothing about the
     * client's booking changes: notary_id, the service and therefore the price
     * stay put, and the document is sealed with the assigned notary's assets.
     * Only handled_by moves, recording who did the work.
     *
     * Safe to call twice; the second call is a no-op.
     */
    public function takeOver(NotarizationRequest $request, string $trigger): void
    {
        if ($request->handled_by !== null) {
            return;
        }

        $this->assignToAdmin($request, $trigger, wasFallback: true);
    }

    /**
     * Put a request on the admin desk.
     *
     * $wasFallback distinguishes a request the platform picked up on a partner's
     * behalf (true) from one the client deliberately booked with the platform's
     * own notary (false) — the client-facing wording and the reporting flag differ.
     */
    private function assignToAdmin(NotarizationRequest $request, string $trigger, bool $wasFallback): void
    {
        DB::transaction(function () use ($request, $trigger, $wasFallback) {
            $adminUser = $this->systemNativeUser();

            $request->update([
                'status'          => $request->status === RequestStatus::Paid || $request->status === RequestStatus::Submitted
                    ? RequestStatus::Accepted
                    : $request->status, // already under way — don't rewind it
                'accepted_at'     => $request->accepted_at ?? now(),
                'was_fallback'    => $wasFallback,
                'handled_by'      => $adminUser?->id,
                'fallback_due_at' => null,
            ]);

            $request->session?->update(['status' => 'scheduled']);

            AuditLogger::record('request.taken_over', 'notarization_request', $request->id, [
                // 'declined' | 'admin_took_over' | 'system_native_selected'
                'trigger'         => $trigger,
                'admin_user_id'   => $adminUser?->id,
                'assigned_notary' => $request->notary?->id,
            ]);

            if ($adminUser) {
                $adminUser->notify(new FallbackAssignedNotification($request, $trigger));
            }

            // Keep the client informed that their request is still being handled.
            $request->client->notify(new RequestAcceptedNotification($request, viaFallback: $wasFallback));
        });
    }

    /** The admin account that operates as the system-native notary. */
    public function systemNativeUser(): ?User
    {
        $profile = NotaryProfile::systemNative()->with('user')->first();

        return $profile?->user
            ?? User::where('role', UserRole::Admin)->first();
    }
}
