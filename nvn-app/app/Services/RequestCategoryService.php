<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\NotarizationRequest;
use App\Models\NotaryService;
use App\Models\User;
use App\Notifications\Admin\RequestCategoryQueriedAlert;
use App\Notifications\RequestCategoryCorrected;
use App\Notifications\RequestCategoryQueried;
use App\Support\AdminAlert;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Sending a request back because it is filed under the wrong category.
 *
 * The rule the whole class exists to keep: **the payment is never given back
 * and never re-taken.** A client who paid ₦20,000 for the wrong service and
 * needs a ₦35,000 one owes ₦15,000, not ₦35,000 — the money already on the
 * request stays on it, and the arithmetic that makes that work
 * (NotarizationRequest::balanceMinor) was already here for part payments.
 *
 * Three moves:
 *   query()    — the desk says this is the wrong category and, usually, which
 *                one it should be. Work pauses; the fallback clock is disarmed
 *                so the notary is not marked late for a request that is
 *                sitting with the client.
 *   resolve()  — the client picks. The service, and therefore the fee, change.
 *                If that leaves a balance the job stays paused until it clears;
 *                if it does not, the desk is put straight back to work.
 *   withdraw() — the desk changes its mind before the client answers.
 */
class RequestCategoryService
{
    /**
     * The desk raises a query. $by is the notary or admin who raised it.
     *
     * @param  NotaryService|null  $suggested  what the desk thinks it really is
     */
    public function query(
        NotarizationRequest $request,
        User $by,
        string $reason,
        ?NotaryService $suggested = null,
    ): void {
        DB::transaction(function () use ($request, $by, $reason, $suggested) {
            $request->update([
                'category_query_at'             => now(),
                'category_query_by'             => $by->id,
                'category_query_reason'         => $reason,
                'category_suggested_service_id' => $suggested?->id,
                'category_query_resolved_at'    => null,
                // The clock measures how long a notary has left a client
                // waiting. This client is the one being waited on, so stop it
                // — and re-arm it in resolve() only if there is still a
                // response owed when the category is settled.
                'fallback_due_at'               => null,
            ]);

            AuditLogger::record('request.category_queried', 'notarization_request', $request->id, [
                'reason'       => $reason,
                'suggested_id' => $suggested?->id,
                'suggested'    => $suggested?->service_type,
                'was'          => $request->service?->service_type,
            ], $by->id);

            $request->client?->notify(new RequestCategoryQueried($request->fresh()->load(
                'service', 'categorySuggestedService', 'notary.user',
            )));

            // The desk keeps a record of its own queries as well, because the
            // admin may be neither the one who raised it nor the one who ends
            // up doing the job, and this is the moment a paid request stops
            // moving. Skipped when the admin raised it themselves.
            if (! $by->isAdmin()) {
                AdminAlert::send(new RequestCategoryQueriedAlert($request, $by, $reason, $suggested));
            }
        });
    }

    /**
     * The client accepts a category. Returns the outstanding balance in minor
     * units — zero means there is nothing more to pay and work has resumed.
     */
    public function resolve(NotarizationRequest $request, NotaryService $chosen, User $by): int
    {
        return DB::transaction(function () use ($request, $chosen, $by) {
            $previous = $request->service;

            $request->update([
                'service_id'                 => $chosen->id,
                'category_query_resolved_at' => now(),
            ]);

            // The slot was sized by the old service. A deed does not fit in an
            // attestation's twenty minutes, so move the end, not the start —
            // the client agreed to the start time.
            $session = $request->session;
            if ($session?->scheduled_start_at) {
                $session->update([
                    'scheduled_end_at' => $session->scheduled_start_at->copy()->addMinutes(
                        $chosen->estimated_duration_minutes ?: config('nvn.session_slot_minutes'),
                    ),
                ]);
            }

            $request->refresh()->load('service');
            $balance = $request->balanceMinor();

            AuditLogger::record('request.category_corrected', 'notarization_request', $request->id, [
                'from'      => $previous?->service_type,
                'to'        => $chosen->service_type,
                'from_fee'  => $previous?->priceFor($request->currency ?: 'NGN'),
                'to_fee'    => $request->feeMinor(),
                'paid'      => $request->amountPaidMinor(),
                'balance'   => $balance,
                'overpaid'  => $request->overpaidMinor(),
            ], $by->id);

            if ($balance === 0) {
                $this->resume($request);
            }

            // Told either way. A balance is the desk's business too — it is
            // why the job they were notified about has not restarted.
            $this->tellTheDesk($request, $chosen, $balance);

            return $balance;
        });
    }

    /** The desk withdraws its own query without the client having to do anything. */
    public function withdraw(NotarizationRequest $request, User $by): void
    {
        DB::transaction(function () use ($request, $by) {
            $request->update([
                'category_query_at'             => null,
                'category_query_by'             => null,
                'category_query_reason'         => null,
                'category_suggested_service_id' => null,
                'category_query_resolved_at'    => null,
            ]);

            AuditLogger::record('request.category_query_withdrawn', 'notarization_request', $request->id, [], $by->id);

            $this->resume($request->refresh());
        });
    }

    /**
     * Put a paused request back in front of the desk.
     *
     * Called when a query is answered with nothing left to pay, when it is
     * withdrawn, and from RequestFulfillmentService::settle() the moment a
     * top-up clears. Only touches a request still waiting on a first response;
     * one the notary had already accepted was never on the clock.
     */
    public function resume(NotarizationRequest $request): void
    {
        if ($request->status !== RequestStatus::Paid || $request->fallback_due_at !== null) {
            return;
        }

        $request->update([
            'notary_notified_at' => now(),
            'fallback_due_at'    => now()->addMinutes(Settings::fallbackMinutes()),
        ]);

        AuditLogger::record('request.category_resumed', 'notarization_request', $request->id, [
            'service' => $request->service?->service_type,
        ], $request->client_id);
    }

    private function tellTheDesk(NotarizationRequest $request, NotaryService $chosen, int $balance): void
    {
        $notification = new RequestCategoryCorrected($request, $chosen, $balance);

        $notaryUser = $request->notary?->user;
        if ($notaryUser) {
            $notaryUser->notify($notification);
        }

        // Not a duplicate for the admin's own requests: AdminAlert skips
        // nobody, but a system-native request has no partner notary above, so
        // this is the only copy that gets sent.
        if (! $notaryUser?->isAdmin()) {
            AdminAlert::send($notification);
        }
    }
}
