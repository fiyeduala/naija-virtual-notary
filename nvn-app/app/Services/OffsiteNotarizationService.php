<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\NotarizationRequest;
use App\Models\NotaryProfile;
use App\Models\Payment;
use App\Models\RequestDocument;
use App\Models\User;
use App\Notifications\Admin\OffsiteJobPaidAlert;
use App\Notifications\OffsiteJobReceipt;
use App\Support\AdminAlert;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Offsite notarization — the notary's own job, brought here only to be sealed.
 *
 * The platform's part is deliberately small: take a fee per document, unlock
 * the editor, hand back the sealed PDF. It does not hold a client account, does
 * not schedule anything, does not verify anybody's identity — the notary did
 * all of that in person, which is the whole point of an offsite job — and does
 * not split the money, because this fee is paid *to* the platform rather than
 * collected on the notary's behalf.
 *
 * The record is still a NotarizationRequest so that the editor, the placement
 * engine, the sealer and the audit trail all work unchanged. Two things are
 * unusual about it and both are load-bearing:
 *
 *  - client_id points at the notary's own user. The column cannot be null, and
 *    on this kind of job the notary genuinely is the platform's customer: they
 *    pay, and they collect the file. Nothing client-facing ever reads it,
 *    because scopeMarketplace() keeps offsite jobs out of every client screen.
 *
 *  - there is no session row. A session means a video appointment, and inventing
 *    one would put a meeting that never happened into the calendar and the
 *    verification record. NotarizeController::finalize() handles its absence.
 */
class OffsiteNotarizationService
{
    /**
     * Start a job: create the record, store the documents, freeze the price.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function create(NotaryProfile $profile, User $notaryUser, string $describedAs, array $files): NotarizationRequest
    {
        return DB::transaction(function () use ($profile, $notaryUser, $describedAs, $files) {
            $request = NotarizationRequest::create([
                'client_id'      => $notaryUser->id,
                'notary_id'      => $profile->id,
                'service_id'     => null,
                'status'         => RequestStatus::Draft,
                'currency'       => 'NGN',
                'is_offsite'     => true,
                // Read once, here, and never again for this job. See the
                // unit_fee_minor column comment in the migration.
                'unit_fee_minor' => Settings::offsiteFeeMinor(),
                'document_use'   => $describedAs,
            ]);

            foreach ($files as $file) {
                $path = $file->store('offsite-documents', 'private');

                RequestDocument::create([
                    'request_id'        => $request->id,
                    'uploaded_by'       => $notaryUser->id,
                    'file_url'          => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_hash_sha256'  => hash_file('sha256', $file->getRealPath()),
                    // 'document' rather than a new type, because this is what
                    // notarizableDocuments() looks for and what the editor
                    // walks. An offsite job is all document and nothing else —
                    // no ID scan, because identity was checked face to face.
                    'file_type'         => 'document',
                ]);
            }

            AuditLogger::record('offsite.created', 'notarization_request', $request->id, [
                'documents'      => count($files),
                'unit_fee_minor' => $request->unit_fee_minor,
                'notary_id'      => $profile->id,
            ], $notaryUser->id);

            return $request->refresh();
        });
    }

    /**
     * Add documents to a job that has not been paid for yet.
     *
     * Allowed only before payment: the fee is per document and the total was
     * agreed at checkout, so a document added afterwards would either be sealed
     * for nothing or silently reopen a balance on a job the notary believes is
     * settled. Both are worse than making them start a second job.
     */
    public function addDocuments(NotarizationRequest $request, array $files, User $by): void
    {
        DB::transaction(function () use ($request, $files, $by) {
            foreach ($files as $file) {
                $path = $file->store('offsite-documents', 'private');

                RequestDocument::create([
                    'request_id'        => $request->id,
                    'uploaded_by'       => $by->id,
                    'file_url'          => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_hash_sha256'  => hash_file('sha256', $file->getRealPath()),
                    'file_type'         => 'document',
                ]);
            }

            AuditLogger::record('offsite.documents_added', 'notarization_request', $request->id, [
                'added' => count($files),
                'total' => $request->fresh()->billableDocumentCount(),
            ], $by->id);
        });
    }

    /**
     * Remove a document from an unpaid job.
     *
     * The file goes with it. Nothing has been sealed and nobody has paid, so
     * there is no record to preserve — keeping the upload would only leave a
     * private file nobody can reach and nobody meant to keep.
     */
    public function removeDocument(NotarizationRequest $request, RequestDocument $document, User $by): void
    {
        DB::transaction(function () use ($request, $document, $by) {
            \Illuminate\Support\Facades\Storage::disk('private')->delete($document->file_url);
            $document->delete();

            AuditLogger::record('offsite.document_removed', 'notarization_request', $request->id, [
                'filename' => $document->original_filename,
                'total'    => $request->fresh()->billableDocumentCount(),
            ], $by->id);
        });
    }

    /**
     * Clear an offsite fee by its Paystack reference.
     *
     * The type is part of the lookup and not an afterthought: a reference is
     * only unique within our own rows, and finding a request_fee here would
     * settle a client's marketplace payment through the wrong path entirely.
     */
    public function settleReference(string $reference): void
    {
        $payment = Payment::where('paystack_reference', $reference)
            ->where('type', 'offsite_fee')
            ->first();

        if ($payment) {
            $this->settle($payment);
        }
    }

    /**
     * Mark the fee cleared, then open the job.
     *
     * Idempotent at the payment row, which is the thing both the webhook and
     * the redirect-back race over.
     */
    public function settle(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if (! $payment || $payment->status === 'successful') {
                return;
            }

            $payment->update([
                'status'       => 'successful',
                'completed_at' => $payment->completed_at ?? now(),
            ]);

            $request = $payment->request()->first();

            // Deliberately not reported to Meta. ReportEventToMeta treats a
            // payment as a purchase by a client the advertising brought in;
            // this is a partner paying us a platform charge, and counting it
            // would teach the optimiser to chase our own notaries.
            if ($request) {
                $this->markPaid($request, $payment);
            }
        });
    }

    /**
     * Open the job for sealing once the fee has cleared.
     *
     * Reached from the Paystack webhook, from the redirect back, and directly
     * when the fee is set to zero. Idempotent, like every other settlement path
     * on this platform: the webhook and the callback both fire on a normal
     * payment and the second one must do nothing.
     */
    public function markPaid(NotarizationRequest $request, ?Payment $payment = null): void
    {
        DB::transaction(function () use ($request, $payment) {
            $request = NotarizationRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $request || $request->status !== RequestStatus::Draft) {
                return;
            }

            $request->update([
                'status'       => RequestStatus::Paid,
                'submitted_at' => $request->submitted_at ?? now(),
                'paid_at'      => now(),
                // Emphatically not set: notary_notified_at and fallback_due_at.
                // Both belong to the response clock, which measures how long a
                // notary has left a *client* waiting. There is no client here,
                // and an armed clock would put this job on the overdue sweep.
            ]);

            AuditLogger::record('offsite.paid', 'notarization_request', $request->id, [
                'amount'    => $payment?->amount ?? 0,
                'settled'   => $payment?->settlement_method ?? ($payment ? 'paystack' : 'free'),
                'documents' => $request->billableDocumentCount(),
            ], $request->client_id);

            $notaryUser = $request->notary?->user;

            if ($notaryUser && $payment) {
                $notaryUser->notify(new OffsiteJobReceipt($request, $payment));
            }

            // Revenue, and revenue of a kind the desk has no other way of
            // seeing — no client raised it and it appears in none of the
            // marketplace queues.
            AdminAlert::send(new OffsiteJobPaidAlert($request, $payment));
        });
    }

    /**
     * Why this notary cannot start an offsite job, or null when they can.
     *
     * Returned as a sentence rather than a boolean because every one of these
     * is fixable and the notary needs to know which one it is. They are about
     * to charge a customer standing in front of them.
     */
    public function blockedReason(?NotaryProfile $profile): ?string
    {
        if (! $profile) {
            return 'Your notary profile is not set up yet.';
        }

        if ($profile->verification_status !== 'approved') {
            return 'Your application is still being reviewed. We cannot seal documents '
                . 'in your name until it is approved.';
        }

        // Deliberately not checked: public listing, and membership expiry with
        // it. Listing is about whether clients can find you in the marketplace,
        // and an offsite job has no client looking — a notary who never wants a
        // marketplace booking should still be able to pay to seal their own
        // work. Blocking it would only refuse money for no reason.
        if (! $profile->canSeal()) {
            return 'Your e-signature, stamp and seal are not all on file yet — '
                . 'there would be nothing to place on the document.';
        }

        return null;
    }
}
