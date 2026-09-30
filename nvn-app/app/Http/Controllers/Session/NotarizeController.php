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
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NotarizeController extends Controller
{
    /** The SmallPDF-style editor. */
    public function edit(NotarizationRequest $request): View|RedirectResponse
    {
        $this->authorizeNotarySide($request);

        // Payment first, on an offsite job too.
        //
        // A marketplace request is payment-first by arrangement rather than by
        // guard: the notary is not told about it and cannot reach it until the
        // client's fee has cleared. An offsite job is the other way round — the
        // notary creates the record themselves and owns it from the first
        // moment — so without this they could upload, open the editor and seal
        // without ever paying. This is the only thing the platform is selling
        // here, so it is the one door that has to be locked.
        if ($request->is_offsite && $request->status === RequestStatus::Draft) {
            return redirect()->route('notary.offsite.show', $request)
                ->withErrors(['offsite' => 'Pay the sealing fee first — the editor opens the moment it clears.']);
        }

        $this->recordTakeOver($request);

        $documents = $request->notarizableDocuments;
        abort_unless($documents->isNotEmpty(), 404);

        $document = $this->currentDocument($request);

        // A Word document is shown as its PDF rendition, so the notary places
        // marks on the same pages the seal will be applied to. See DocxRenderer.
        $ext = $this->renditionFor($document)
            ? 'pdf'
            : strtolower(pathinfo($document->original_filename ?? $document->file_url, PATHINFO_EXTENSION));

        $assetSets = $this->availableAssetSets($request);

        // Worth a line on the record even before anything is placed: a paid job
        // whose notary cannot seal is a stuck job, and this is the moment it was
        // found. The ordinary desk substitution is not logged here — it is
        // offered on every partner request now, so it would say nothing; that
        // one is recorded when marks are actually saved.
        if ($request->notary && ! $request->notary->canSeal()) {
            AuditLogger::record('notarize.platform_seal_offered', 'notarization_request', $request->id, [
                'assigned_notary_id' => $request->notary->id,
                'missing'            => $this->missingMarks($request->notary),
                'offered'            => collect($assetSets)->contains(fn (array $s) => $s['substitute']),
            ], Auth::id());
        }

        return view('session.notarize', [
            'request'    => $request,
            'documents'  => $documents,
            'document'   => $document,
            'fileExt'    => $ext,
            'assetSets'  => $assetSets,
            // The client's own signature image, if they sent one — see
            // clientSignatures().
            'clientSignatures' => $this->clientSignatures($request, $document),
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

        // Serve the PDF rendition of a Word document rather than the .docx
        // itself: the editor can only place marks accurately on the same pages
        // the sealing service will draw them on.
        if ($rendition = $this->renditionFor($document)) {
            return Storage::disk('private')->response(
                $rendition,
                pathinfo($document->original_filename ?? 'document', PATHINFO_FILENAME) . '.pdf',
                [
                    'Content-Type'        => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="document.pdf"',
                ],
            );
        }

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

        // Being allowed on this request is not the same as being allowed at this
        // notary's marks. Without this, any id in the URL streamed any notary's
        // signature to anyone who could open one request.
        abort_unless(in_array($asset->id, $this->allowedAssetIds($request), true), 404);

        return Storage::disk('private')->response($asset->file_url);
    }

    /**
     * Stream one of the client's own signature images (authorized).
     *
     * Deliberately not the same endpoint as asset(): that one is scoped to a
     * notary's marks, this one to this request's uploads, and neither list may
     * leak into the other. The id is checked against the same list the palette
     * was built from, so a number edited in the URL cannot reach another
     * client's file — or this client's identification, which is not offered
     * here at all.
     */
    public function clientSignature(NotarizationRequest $request, \App\Models\RequestDocument $signature)
    {
        $this->authorizeNotarySide($request);

        abort_unless(
            in_array($signature->id, $this->allowedSignatureDocumentIds($request), true),
            404,
        );

        return Storage::disk('private')->response($signature->file_url);
    }

    /** Save the current set of placements (replace-all for the document). */
    public function savePlacements(NotarizationRequest $request, Request $http): JsonResponse
    {
        $this->authorizeNotarySide($request);

        $data = $http->validate([
            'placements'              => ['present', 'array'],
            'placements.*.type'       => ['required', 'in:asset,text'],
            // Scoped to the sets this operator was offered, not to the assets
            // table — see allowedAssetIds().
            'placements.*.asset_id'   => ['nullable', Rule::in($this->allowedAssetIds($request))],
            // The client's own signature image. Same reasoning as asset_id: the
            // palette is only the drawn part of the boundary, so the list is
            // asked again here. It is scoped to this request's own uploads, and
            // the identification scan is not in it.
            'placements.*.signature_document_id' => [
                'nullable',
                Rule::in($this->allowedSignatureDocumentIds($request)),
            ],
            'placements.*.text_value' => ['nullable', 'string', 'max:500'],
            'placements.*.page'       => ['required', 'integer', 'min:1'],
            'placements.*.x'          => ['required', 'numeric', 'between:0,1'],
            'placements.*.y'          => ['required', 'numeric', 'between:0,1'],
            'placements.*.width'      => ['nullable', 'numeric', 'between:0,1'],
            'placements.*.height'     => ['nullable', 'numeric', 'between:0,1'],
        ], [
            'placements.*.asset_id.in' => 'That signature, stamp or seal is not one you may place on this document.',
            'placements.*.signature_document_id.in' => 'That signature is not one the client uploaded to this request.',
        ]);

        // An image mark that names no image. Nothing can be drawn from it, and
        // storing it would mean the notary sees the item in the editor, seals,
        // and finds the document without it — which is exactly what happened
        // the first time a browser kept running a cached notarize-editor.js
        // from before signature_document_id existed. Refused out loud instead.
        foreach ($data['placements'] as $p) {
            if (($p['type'] ?? null) === 'asset'
                && blank($p['asset_id'] ?? null)
                && blank($p['signature_document_id'] ?? null)) {
                return response()->json([
                    'message' => 'This page is running an old copy of the editor, so one of your marks '
                        . 'arrived with no image attached and nothing was saved. Reload the page '
                        . '(Ctrl+Shift+R, or ⌘+Shift+R on a Mac) and place it again.',
                ], 422);
            }
        }

        $document = $this->currentDocument($request);

        DB::transaction(function () use ($document, $data) {
            DocumentPlacement::where('document_id', $document->id)->delete();

            foreach ($data['placements'] as $p) {
                DocumentPlacement::create([
                    'document_id' => $document->id,
                    'type'        => $p['type'],
                    'asset_id'    => $p['asset_id'] ?? null,
                    // Never both: a mark is the notary's or it is the client's.
                    'signature_document_id' => ($p['asset_id'] ?? null)
                        ? null
                        : ($p['signature_document_id'] ?? null),
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

        $this->recordPlatformSealUse($request, $document, $data['placements']);
        $this->recordClientSignatureUse($request, $document, $data['placements']);

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

        // An offsite job has no session, and must not be given one. A session
        // means a verification call took place on this platform; the notary met
        // this person themselves, off this platform, which is the entire point.
        // Manufacturing a session row would write a meeting that never happened
        // into the calendar and into the verification record.
        abort_unless($session || $request->is_offsite, 404);

        // The same payment gate as edit(), because finalize is a POST and a
        // stale form or a hand-made request would otherwise reach the sealer
        // without passing the editor.
        if ($request->is_offsite && $request->status === RequestStatus::Draft) {
            return redirect()->route('notary.offsite.show', $request)
                ->withErrors(['offsite' => 'Pay the sealing fee first — the editor opens the moment it clears.']);
        }

        // Guard: the category is under query, or was corrected upwards and the
        // difference has not arrived. Placements can go on — losing that work
        // would be its own punishment — but the seal cannot, because a sealed
        // document is a delivered job and there is no taking it back if the
        // client never pays the balance.
        if ($request->isCategoryBlocked()) {
            return back()->withErrors([
                'placements' => $request->hasOpenCategoryQuery()
                    ? 'This request is filed under the wrong category and the client has been asked '
                        . 'to re-pick. Nothing can be sealed until they answer.'
                    : 'The corrected category costs more and ' . $request->displayBalance()
                        . ' is still outstanding. It will come back to you the moment that clears.',
            ]);
        }

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
        //
        // Skipped entirely for an offsite job. There is no session to hang the
        // record on, and "uploaded_id" would be a false statement about how
        // identity was checked: nobody uploaded an ID here, the notary saw the
        // person. The platform has no evidence of that and should not pretend to.
        if ($session && ! $session->identity_verified) {
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

        $request->session?->update(['status' => 'completed', 'actual_end_at' => now()]);

        $request->update([
            'status'       => RequestStatus::Completed,
            'completed_at' => now(),
        ]);

        AuditLogger::record('request.completed', 'notarization_request', $request->id, [
            'final_document_ids' => $finals->pluck('id')->all(),
            'offsite'            => $request->is_offsite,
        ], Auth::id());

        // An offsite job ends here, on the notary's own screen. There is no
        // client account to notify — client_id points at the notary themselves
        // — and the sealed file is theirs to download and hand over however
        // they took the job on in the first place.
        if ($request->is_offsite) {
            return redirect()->route('notary.offsite.show', $request)
                ->with('status', $finals->count() > 1
                    ? 'Sealed — ' . $finals->count() . ' documents are ready to download.'
                    : 'Sealed. Your document is ready to download.');
        }

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
    /**
     * The PDF rendition of a Word upload, or null for everything else.
     *
     * Both the editor and the file stream ask this, and they must always get
     * the same answer: if one showed the .docx and the other the rendition, the
     * marks would be recorded against a page that does not exist.
     */
    private function renditionFor(\App\Models\RequestDocument $document): ?string
    {
        return app(\App\Services\DocxRenderer::class)->renditionFor($document);
    }

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
     * The default is unchanged and is still the rule for partner notaries: the
     * document carries the seal of the notary the client selected. The client
     * chose them, paid their price, and the record names them — so a partner at
     * this keyboard sees exactly one set, their own, and there is no way for one
     * notary to reach another's marks.
     *
     * The admin desk is the exception, and now always has the platform's own
     * marks available as a second, clearly-labelled set. It used to appear only
     * when the assigned notary could not seal at all — which caught the notary
     * with a missing stamp and missed the worse case: a notary whose three marks
     * are all present and all wrong. Nothing in the database can tell those
     * apart, because "wrong" is a judgement about the image itself; only a human
     * looking at it knows. So the desk gets the choice and the record says which
     * was taken, rather than the system guessing and being unable to be
     * overruled. This is the option to reach for when a partner has uploaded the
     * wrong file, or their marks cannot lawfully go on this particular document.
     *
     * Whichever set is used goes on the document *and* into the sealed PDF's
     * authorship — see PdfNotarizationService::sealAuthor(). notary_id is not
     * touched: the notary of record is still whoever the client booked.
     */
    private function availableAssetSets(NotarizationRequest $request): array
    {
        $user = Auth::user();
        $sets = [];

        $assigned = $request->notary;
        $assigned?->loadMissing('assets', 'user');

        if ($assigned && $assigned->canSeal()) {
            $sets[] = [
                'label'      => $assigned->is_system_native
                    ? 'Naija Virtual Notary (platform seal)'
                    : $assigned->user->full_name . ' — assigned notary',
                'note'       => null,
                'assets'     => $assigned->assets,
                'substitute' => false,
            ];
        }

        // Only the desk may substitute one notary's marks for another's, and
        // there is nothing to substitute when the platform's notary is already
        // the notary of record — that set is the one above.
        $atTheDesk = $user->isAdmin() || $user->id === $request->handled_by;

        if (! $atTheDesk || $assigned?->is_system_native) {
            return $sets;
        }

        $systemNative = NotaryProfile::systemNative()->with('assets', 'user')->first();

        if (! $systemNative || ! $systemNative->canSeal()) {
            return $sets;
        }

        $sets[] = [
            'label'      => 'Naija Virtual Notary (platform seal)',
            'note'       => $sets === []
                ? 'The assigned notary cannot seal — ' . $this->missingMarks($assigned)
                    . ' missing. These marks are the only way to finish this job.'
                : 'Substitutes the platform’s marks for the assigned notary’s. Use when '
                    . 'theirs are wrong or cannot go on this document. The choice is recorded.',
            'assets'     => $systemNative->assets,
            'substitute' => true,
        ];

        return $sets;
    }

    /**
     * Every asset id the person at this keyboard is allowed to place.
     *
     * The palette in the editor is not the boundary — it is only the part of the
     * boundary that is drawn. asset_id arrives from the browser, and validating
     * it as `exists:notary_assets,id` accepted *any* notary's signature: a
     * partner could have placed a colleague's seal, or streamed one, by editing
     * a number in the request. The answer is the same list the editor was built
     * from, asked again on the way back in.
     *
     * @return list<int>
     */
    private function allowedAssetIds(NotarizationRequest $request): array
    {
        return collect($this->availableAssetSets($request))
            ->flatMap(fn (array $set) => $set['assets']->pluck('id'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The client's own signature images, ready to be placed on this document.
     *
     * Two things arrive as one: the signature drawn on the intake canvas
     * (file_type 'client_signature'), and a photograph or scan of a signature
     * the client uploaded as an ordinary attachment. Both mean the same thing —
     * "this is my hand, put it on the document" — and neither needs a call.
     *
     * Only images qualify, and the identification scan never does: an ID card
     * is evidence the notary looked at, not a mark anybody consented to have
     * stamped onto a deed. Sealed output is excluded, and so is the document
     * currently open, which cannot be pasted into itself.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\RequestDocument>
     */
    private function clientSignatures(
        NotarizationRequest $request,
        ?\App\Models\RequestDocument $current = null,
    ): \Illuminate\Support\Collection {
        return $request->documents()
            ->where('is_final_notarized', false)
            ->whereNotIn('file_type', ['identification', 'final_notarized'])
            ->orderByRaw("CASE WHEN file_type = 'client_signature' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get()
            ->reject(fn ($d) => $current && $d->id === $current->id)
            ->filter(fn ($d) => in_array(
                strtolower(pathinfo($d->original_filename ?? $d->file_url, PATHINFO_EXTENSION)),
                ['png', 'jpg', 'jpeg'],
                true,
            ))
            ->values();
    }

    /**
     * Every upload on this request whose image the operator may place.
     *
     * Asked again on save and on the image stream, for the same reason
     * allowedAssetIds() is — the browser sends the id, so the browser cannot be
     * the one that decides which ids are acceptable. The current document is
     * not excluded here: the notary may have moved to another tab between
     * placing a mark and saving, and the boundary that matters is "an image on
     * this request", not "an image on some other tab".
     *
     * @return list<int>
     */
    private function allowedSignatureDocumentIds(NotarizationRequest $request): array
    {
        return $this->clientSignatures($request)->pluck('id')->all();
    }

    /**
     * Note that the client's own signature went onto the document.
     *
     * The one fact nobody can reconstruct afterwards: the notary, not the
     * client, put the client's signature where it is. It was done on the
     * client's own uploaded signature and at their request, which is the normal
     * way this works — and it is exactly the sort of thing that gets questioned
     * a year later, so the record says which image, on which document, by whom
     * and when.
     */
    private function recordClientSignatureUse(
        NotarizationRequest $request,
        \App\Models\RequestDocument $document,
        array $placements,
    ): void {
        $ids = collect($placements)
            ->reject(fn ($p) => filled($p['asset_id'] ?? null))
            ->pluck('signature_document_id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        AuditLogger::record('document.client_signature_placed', 'notarization_request', $request->id, [
            'document_id'           => $document->id,
            'signature_document_ids'=> $ids->all(),
            'count'                 => collect($placements)->filter(fn ($p) => filled($p['signature_document_id'] ?? null))->count(),
            'client_id'             => $request->client_id,
        ], Auth::id());
    }

    /**
     * Note when the platform's marks — not the assigned notary's — were placed.
     *
     * Recorded on save rather than when the option is offered. The desk now sees
     * the platform set on every partner request it opens, so "offered" is the
     * ordinary case and says nothing; "used" is the answer to the question that
     * gets asked months later, which is whose seal is actually on the client's
     * document and who put it there.
     */
    private function recordPlatformSealUse(
        NotarizationRequest $request,
        \App\Models\RequestDocument $document,
        array $placements,
    ): void {
        $assigned = $request->notary;

        if (! $assigned || $assigned->is_system_native) {
            return; // the platform's marks are the assigned notary's marks
        }

        $assetIds = collect($placements)->pluck('asset_id')->filter()->unique();

        if ($assetIds->isEmpty()) {
            return;
        }

        $systemNative = NotaryProfile::systemNative()->first();

        $substituted = $systemNative && NotaryAsset::whereIn('id', $assetIds)
            ->where('notary_profile_id', $systemNative->id)
            ->exists();

        if (! $substituted) {
            return;
        }

        AuditLogger::record('notarize.platform_seal_used', 'notarization_request', $request->id, [
            'document_id'        => $document->id,
            'assigned_notary_id' => $assigned->id,
            'assigned_can_seal'  => $assigned->canSeal(),
            'missing'            => $assigned->canSeal() ? null : $this->missingMarks($assigned),
        ], Auth::id());
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
            || $user->id === $request->notary?->user_id
            // Nothing to take over on an offsite job. There is no client
            // waiting, no clock running and no fallback to step into — the
            // notary bought the sealing and is the only one meant to do it.
            || $request->is_offsite) {
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
