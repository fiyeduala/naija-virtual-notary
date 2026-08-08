<?php

namespace App\Http\Controllers\Session;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\DocumentPlacement;
use App\Models\NotarizationRequest;
use App\Models\NotaryAsset;
use App\Models\NotaryProfile;
use App\Notifications\DocumentReadyNotification;
use App\Services\PdfNotarizationService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class NotarizeController extends Controller
{
    /** The SmallPDF-style editor. */
    public function edit(NotarizationRequest $request): View|RedirectResponse
    {
        $this->authorizeNotarySide($request);
        $this->recordTakeOver($request);

        $documents = $request->notarizableDocuments;
        abort_unless($documents->isNotEmpty(), 404);

        $document = $this->currentDocument($request);

        $ext = strtolower(pathinfo($document->original_filename ?? $document->file_url, PATHINFO_EXTENSION));

        return view('session.notarize', [
            'request'    => $request,
            'documents'  => $documents,
            'document'   => $document,
            'fileExt'    => $ext,
            'assetSets'  => $this->availableAssetSets($request),
            'placements' => $document->placements()->get(),
            // Which of the others are still bare, so the editor can say so
            // before the notary reaches finalize and is turned back.
            'pending'    => $this->unplacedDocuments($request),
        ]);
    }

    /** Stream the source document to the editor (authorized). Serves correct MIME for all types. */
    public function document(NotarizationRequest $request)
    {
        $this->authorizeNotarySide($request);
        $document = $this->currentDocument($request);

        $ext  = strtolower(pathinfo($document->original_filename ?? $document->file_url, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf'        => 'application/pdf',
            'jpg', 'jpeg'=> 'image/jpeg',
            'png'        => 'image/png',
            'docx'       => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc'        => 'application/msword',
            default      => 'application/octet-stream',
        };

        $filename = $document->original_filename ?? ('document.' . $ext);

        return Storage::disk('private')->response($document->file_url, $filename, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    /** Stream an asset image (authorized). */
    public function asset(NotarizationRequest $request, NotaryAsset $asset)
    {
        $this->authorizeNotarySide($request);
        abort_unless($asset->file_url, 404);

        return Storage::disk('private')->response($asset->file_url);
    }

    /** Save the current set of placements (replace-all for the document). */
    public function savePlacements(NotarizationRequest $request, Request $http): JsonResponse
    {
        $this->authorizeNotarySide($request);

        $data = $http->validate([
            'placements'              => ['present', 'array'],
            'placements.*.type'       => ['required', 'in:asset,text'],
            'placements.*.asset_id'   => ['nullable', 'exists:notary_assets,id'],
            'placements.*.text_value' => ['nullable', 'string', 'max:500'],
            'placements.*.page'       => ['required', 'integer', 'min:1'],
            'placements.*.x'          => ['required', 'numeric', 'between:0,1'],
            'placements.*.y'          => ['required', 'numeric', 'between:0,1'],
            'placements.*.width'      => ['nullable', 'numeric', 'between:0,1'],
            'placements.*.height'     => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $document = $this->currentDocument($request);

        DB::transaction(function () use ($document, $data) {
            DocumentPlacement::where('document_id', $document->id)->delete();

            foreach ($data['placements'] as $p) {
                DocumentPlacement::create([
                    'document_id' => $document->id,
                    'type'        => $p['type'],
                    'asset_id'    => $p['asset_id'] ?? null,
                    'text_value'  => $p['text_value'] ?? null,
                    'page'        => $p['page'],
                    'x'           => $p['x'],
                    'y'           => $p['y'],
                    'width'       => $p['width'] ?? null,
                    'height'      => $p['height'] ?? null,
                    'placed_by'   => Auth::id(),
                ]);
            }
        });

        AuditLogger::record('document.placements_saved', 'notarization_request', $request->id, [
            'document_id' => $document->id,
            'count'       => count($data['placements']),
        ], Auth::id());

        return response()->json([
            'saved'   => count($data['placements']),
            // Named here so the editor can tell the notary what is still bare
            // without a page reload after every save.
            'pending' => $this->unplacedDocuments($request)
                ->map(fn ($d) => ['id' => $d->id, 'label' => $d->label()])
                ->values(),
        ]);
    }

    /** Finalize: generate the sealed PDF, complete the request, notify the client. */
    public function finalize(NotarizationRequest $request, PdfNotarizationService $pdf): RedirectResponse
    {
        $this->authorizeNotarySide($request);

        $session = $request->session;
        abort_unless($session, 404);

        // Guard: never seal a document with nothing on it. Every document is
        // checked, not just the primary one — the client paid for each of them
        // and an unsealed extra is the failure they would only discover after
        // presenting it. The ones still bare are named, because the notary is
        // in front of a client and "one of your documents" is not actionable.
        $bare = $this->unplacedDocuments($request);

        if ($bare->isNotEmpty()) {
            return back()->withErrors([
                'placements' => 'Nothing has been placed on ' . $bare->map->label()->join(', ', ' and ')
                    . '. Open ' . ($bare->count() > 1 ? 'each one' : 'it') . ' from the tabs above, '
                    . 'place your signature, stamp or seal, click "Save placements", then finalize.',
            ]);
        }

        // Auto-create a verification record if the notary went straight to notarize
        // without a live call — treated as "uploaded_id" method.
        if (! $session->identity_verified) {
            $idDoc = $request->documents()->where('file_type', 'identification')->first();
            \App\Models\VerificationRecord::updateOrCreate(
                ['session_id' => $session->id],
                [
                    'notary_id'      => \Illuminate\Support\Facades\Auth::id(),
                    'client_id'      => $request->client_id,
                    'id_document_id' => $idDoc?->id,
                    'method'         => 'uploaded_id',
                    'verified_at'    => now(),
                    'ip_address'     => request()->ip(),
                ],
            );
            $session->update(['verification_method' => 'uploaded_id', 'identity_verified' => true]);
        }

        // A document the sealing engine cannot open is not an error in the
        // ordinary sense — nothing is broken and nothing is lost. It is news
        // for the notary, who is standing in front of a client, so it goes back
        // to the editor with the placements intact rather than to an error page.
        try {
            $finals = $pdf->generate($request);
        } catch (\App\Exceptions\DocumentNotImportableException $e) {
            AuditLogger::record('request.seal_refused', 'notarization_request', $request->id, [
                'reason' => 'unsupported_pdf',
            ], Auth::id());

            return back()->withErrors(['document' => $e->getMessage()]);
        }

        $session = $request->session;
        $session->update(['status' => 'completed', 'actual_end_at' => now()]);

        $request->update([
            'status'       => RequestStatus::Completed,
            'completed_at' => now(),
        ]);

        AuditLogger::record('request.completed', 'notarization_request', $request->id, [
            'final_document_ids' => $finals->pluck('id')->all(),
        ], Auth::id());

        $request->client->notify(new DocumentReadyNotification($request));

        return redirect()->route('session.done', $request)
            ->with('status', $finals->count() > 1
                ? 'Notarization complete — ' . $finals->count() . ' sealed documents. The client has been notified.'
                : 'Notarization complete. The client has been notified.');
    }

    public function done(NotarizationRequest $request): View
    {
        $this->authorizeNotarySide($request);

        return view('session.done', ['request' => $request]);
    }

    /**
     * The document the notary is working on right now.
     *
     * The editor is still a one-document editor; a request with several of them
     * moves between them with ?document=<id>, which is why every endpoint the
     * editor calls resolves it the same way instead of assuming the primary
     * upload. The id is checked against this request's own notarizable set, so
     * it cannot be pointed at another client's file or at the ID scan.
     */
    private function currentDocument(NotarizationRequest $request): \App\Models\RequestDocument
    {
        $documents = $request->notarizableDocuments;

        $requested = request('document');

        $document = $requested
            ? $documents->firstWhere('id', (int) $requested)
            : $documents->first();

        abort_unless($document, 404);

        return $document;
    }

    /**
     * Documents on this request with nothing placed on them yet.
     *
     * One query for the whole set rather than one per document: the guard, the
     * editor's tab strip and the save response all ask this question, and on a
     * five-document request that would otherwise be fifteen round trips.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\RequestDocument>
     */
    private function unplacedDocuments(NotarizationRequest $request): \Illuminate\Support\Collection
    {
        $documents = $request->notarizableDocuments;

        $placed = DocumentPlacement::whereIn('document_id', $documents->pluck('id'))
            ->distinct()
            ->pluck('document_id')
            ->all();

        return $documents->reject(fn ($d) => in_array($d->id, $placed, true))->values();
    }

    /**
     * Which asset sets may be placed on this document.
     *
     * One rule, whoever is at the keyboard: the document carries the seal of the
     * notary the client selected. The client chose them, paid their price, and
     * the certificate names them — so when the platform notarizes on a partner's
     * behalf it does it under that partner's signature, stamp and seal, not its
     * own. The platform's own seal appears only when the platform's notary is
     * the one the client selected, and it comes through this same branch because
     * notary_id already points at the system-native profile.
     *
     * The one exception is a safety valve: if the assigned notary cannot seal —
     * signature, stamp or seal missing, or the file behind one of them gone —
     * there is nothing complete to place and the job cannot be finished, so the
     * system set is offered instead, clearly labelled and recorded, because
     * using it changes whose seal is on the client's document.
     *
     * The valve used to open only when the notary had no assets *at all*, which
     * missed the case it existed for: a partial set is worse than an empty one,
     * because it goes on the document and looks finished. It is now the same
     * question asked when a notary is listed — NotaryProfile::canSeal().
     *
     * This should not fire in normal operation: nothing can be listed or booked
     * without those three marks on file. It is here for what happens *after*
     * listing — an asset deleted, or a file that did not survive a host move —
     * on a job the client has already paid for.
     */
    private function availableAssetSets(NotarizationRequest $request): array
    {
        $user = Auth::user();
        $sets = [];

        $assigned = $request->notary;
        $assigned?->loadMissing('assets', 'user');

        if ($assigned && $assigned->canSeal()) {
            $sets[] = [
                'label'  => $assigned->is_system_native
                    ? 'Naija Virtual Notary (platform seal)'
                    : $assigned->user->full_name . ' — assigned notary',
                'assets' => $assigned->assets,
            ];
        }

        $canUseSystemSeal = $user->isAdmin() || $user->id === $request->handled_by;

        if ($canUseSystemSeal && $sets === []) {
            $systemNative = NotaryProfile::systemNative()->with('assets', 'user')->first();

            if ($systemNative && $systemNative->canSeal()) {
                $sets[] = [
                    'label'  => 'Naija Virtual Notary (platform seal — assigned notary cannot seal: '
                        . $this->missingMarks($assigned) . ' missing)',
                    'assets' => $systemNative->assets,
                ];

                // Substituting one notary's seal for another's is the kind of
                // thing that has to be answerable months later, so it is written
                // down when the option is raised rather than only if it is used.
                AuditLogger::record('notarize.platform_seal_offered', 'notarization_request', $request->id, [
                    'assigned_notary_id' => $assigned?->id,
                    'missing'            => $this->missingMarks($assigned),
                ]);
            }
        }

        return $sets;
    }

    /** Which of the three marks the assigned notary is short of, in plain words. */
    private function missingMarks(?NotaryProfile $profile): string
    {
        if (! $profile) {
            return 'no notary assigned';
        }

        $held = $profile->assets
            ->filter(fn ($a) => filled($a->file_url))
            ->pluck('type')
            ->all();

        $missing = array_values(array_diff(NotaryProfile::SEALING_ASSETS, $held));

        return $missing === [] ? 'assets unusable' : implode(', ', $missing);
    }

    /**
     * An admin opening the editor on a request assigned to a partner is doing
     * the work on that partner's behalf — there is no waiting period for this.
     * Recording it here keeps the desk scope, the message thread and the audit
     * trail agreeing on who is holding the job. Nothing the client sees moves:
     * the assigned notary, the price and the seal stay exactly as booked.
     */
    private function recordTakeOver(NotarizationRequest $request): void
    {
        $user = Auth::user();

        if ($request->handled_by !== null
            || ! $user->isAdmin()
            || $user->id === $request->notary?->user_id) {
            return;
        }

        app(\App\Services\RequestFulfillmentService::class)->takeOver($request, 'admin_took_over');

        $request->refresh();
    }

    private function authorizeNotarySide(NotarizationRequest $request): void
    {
        $user = Auth::user();
        $allowed = $user->id === $request->notary?->user_id
            || $user->isAdmin()
            || $user->id === $request->handled_by;

        abort_unless($allowed, 403);
    }
}
