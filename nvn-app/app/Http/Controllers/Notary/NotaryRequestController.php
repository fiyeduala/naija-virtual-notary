<?php

namespace App\Http\Controllers\Notary;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Models\NotaryService;
use App\Models\RequestDocument;
use App\Services\RequestCategoryService;
use App\Services\RequestFulfillmentService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class NotaryRequestController extends Controller
{
    public function __construct(
        private RequestFulfillmentService $fulfillment,
        private RequestCategoryService $categories,
    ) {}

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
        $request->load('client', 'service', 'session', 'documents',
            'categorySuggestedService', 'categoryQueriedBy');

        return view('notary.requests.show', [
            'request'  => $request,
            'services' => $this->notaryServices($request),
        ]);
    }

    /**
     * "This is not the category it was booked under."
     *
     * Open to the assigned notary and to the admin both, because the admin is
     * the one who sees every request and the only one who sees them before the
     * partner has answered. Nothing is refunded and nothing is cancelled — the
     * client re-picks and pays any difference, which is the whole reason this
     * exists instead of a cancel-and-rebook.
     */
    public function queryCategory(NotarizationRequest $request, Request $http): RedirectResponse
    {
        $this->authorizeNotary($request);

        if (! $request->isPriced()) {
            return back()->with('status', 'There is no category on this request to query yet.');
        }

        if (! in_array($request->status, RequestStatus::active(), true)) {
            return back()->with('status', 'This request is finished — the category can no longer be changed.');
        }

        if ($request->hasOpenCategoryQuery()) {
            return back()->with('status', 'A query is already open on this request, waiting on the client.');
        }

        $validated = $http->validate([
            'reason'       => ['required', 'string', 'max:1000'],
            'service_id'   => ['nullable', 'integer'],
        ]);

        // A recommendation has to come off the assigned notary's own price
        // list, or the fee it produces would not be a price this notary offers.
        $suggested = ! empty($validated['service_id'])
            ? $this->notaryServices($request)->firstWhere('id', (int) $validated['service_id'])
            : null;

        if (! empty($validated['service_id']) && ! $suggested) {
            return back()->withErrors(['service_id' => 'That is not one of this notary\'s services.']);
        }

        if ($suggested && $suggested->id === $request->service_id) {
            return back()->withErrors([
                'service_id' => 'That is the category it is already booked under. Recommend a different one, or leave the recommendation blank.',
            ]);
        }

        $this->categories->query($request, Auth::user(), $validated['reason'], $suggested);

        return redirect()->route('notary.requests.show', $request)->with(
            'status',
            'Sent back to the client to re-pick. Their payment stays on the request — they will '
                . 'only be asked for the difference, if the right category costs more.',
        );
    }

    /** The desk changes its mind before the client has answered. */
    public function withdrawCategoryQuery(NotarizationRequest $request): RedirectResponse
    {
        $this->authorizeNotary($request);

        if (! $request->hasOpenCategoryQuery()) {
            return back()->with('status', 'There is no open query on this request.');
        }

        $this->categories->withdraw($request, Auth::user());

        return redirect()->route('notary.requests.show', $request)
            ->with('status', 'Query withdrawn. The request is back on your desk as it was booked.');
    }

    /**
     * The assigned notary's active price list — the only categories this
     * request can move between, since the notary of record does not change.
     *
     * @return \Illuminate\Support\Collection<int, NotaryService>
     */
    private function notaryServices(NotarizationRequest $request): \Illuminate\Support\Collection
    {
        if (! $request->notary_id) {
            return collect();
        }

        return NotaryService::where('notary_profile_id', $request->notary_id)
            ->where('active', true)
            ->orderBy('service_type')
            ->get();
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
