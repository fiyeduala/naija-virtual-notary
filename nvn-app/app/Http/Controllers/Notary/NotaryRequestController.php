<?php

namespace App\Http\Controllers\Notary;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Models\RequestDocument;
use App\Services\RequestFulfillmentService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class NotaryRequestController extends Controller
{
    public function __construct(private RequestFulfillmentService $fulfillment) {}

    /**
     * Paid requests awaiting a response.
     *
     * A partner notary sees their own desk. The admin sees every unanswered
     * request on the platform, because they may take any of them over on the
     * assigned notary's behalf at any time — longest-waiting first, since that
     * is the only ordering that matters on this screen.
     */
    public function incoming(): View
    {
        $user = Auth::user();

        $query = NotarizationRequest::query()
            ->where('status', RequestStatus::Paid)
            ->with('client', 'service', 'session', 'notary.user');

        if (! $user->isAdmin()) {
            $query->onDeskOf($user);
        }

        return view('notary.requests.incoming', [
            'requests'    => $query->oldest('paid_at')->get(),
            'isAdminDesk' => $user->isAdmin(),
        ]);
    }

    public function show(NotarizationRequest $request): View
    {
        $this->authorizeNotary($request);
        $request->load('client', 'service', 'session', 'documents');

        return view('notary.requests.show', ['request' => $request]);
    }

    /**
     * Stream one of the request's uploaded files to the assigned notary.
     *
     * The session-side equivalent (session.document) only serves the single
     * `document` record and only once the session is under way — but a notary
     * has to see what they are being asked to notarize, and the client's ID,
     * *before* deciding to accept. This serves any file on the request, inline,
     * to the assigned notary only.
     */
    public function document(NotarizationRequest $request, RequestDocument $document)
    {
        $this->authorizeNotary($request);

        // Guard against /notary/requests/5/documents/99 pulling someone else's file.
        abort_unless($document->request_id === $request->id, 404);

        $ext  = strtolower(pathinfo($document->original_filename ?? $document->file_url, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf'         => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'docx'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc'         => 'application/msword',
            default       => 'application/octet-stream',
        };

        $filename = $document->original_filename ?? ('document.' . $ext);

        AuditLogger::record('document.viewed_by_notary', 'notarization_request', $request->id, [
            'document_id' => $document->id,
        ], Auth::id());

        return Storage::disk('private')->response($document->file_url, $filename, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    public function accept(NotarizationRequest $request): RedirectResponse
    {
        $this->authorizeNotary($request);

        if ($request->status !== RequestStatus::Paid) {
            return back()->with('status', 'This request is no longer awaiting your response.');
        }

        // An admin accepting a request booked with a partner is taking it over,
        // not answering as that partner — record who is actually holding it.
        $user = Auth::user();

        if ($user->isAdmin() && $user->id !== $request->notary?->user_id) {
            $this->fulfillment->takeOver($request, 'admin_took_over');

            return redirect()->route('notary.requests.show', $request)->with(
                'status',
                'You have taken this over on ' . ($request->notary?->user?->full_name ?? 'the assigned notary')
                    . '\'s behalf. It will be sealed with their signature, stamp and seal.',
            );
        }

        $this->fulfillment->accept($request);

        return redirect()->route('notary.requests.incoming')
            ->with('status', 'Request accepted. The session is now scheduled.');
    }

    public function decline(NotarizationRequest $request, Request $http): RedirectResponse
    {
        $this->authorizeNotary($request);

        if ($request->status !== RequestStatus::Paid) {
            return back()->with('status', 'This request is no longer awaiting your response.');
        }

        // Declining is the assigned notary's decision to make. An admin looking
        // at a partner's request takes it over instead — there is nowhere for a
        // decline to send it, since the admin is already the last resort.
        $user = Auth::user();

        if ($user->isAdmin() && $user->id !== $request->notary?->user_id) {
            return back()->with('status', 'Use "Take over" — a request booked with a partner cannot be declined from the admin desk.');
        }

        $http->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $this->fulfillment->decline($request, $http->input('reason'));

        return redirect()->route('notary.requests.incoming')
            ->with('status', 'Request declined. It has been reassigned so the client is not left waiting.');
    }

    /**
     * The request must be on this user's desk — assigned to their profile, or
     * handed to them personally. Admins pass unconditionally: they may notarize
     * on any partner's behalf, so every request is reachable from their desk.
     * This mirrors authorizeNotarySide() in the session controllers.
     */
    private function authorizeNotary(NotarizationRequest $request): void
    {
        $user = Auth::user();

        $allowed = $user->isAdmin()
            || ($request->notary_id !== null && $request->notary_id === $user->notaryProfile?->id)
            || $request->handled_by === $user->id;

        abort_unless($allowed, 403);
    }
}
