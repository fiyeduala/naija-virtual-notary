<?php

namespace App\Http\Controllers\Client;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Models\NotaryProfile;
use App\Models\NotaryService;
use App\Models\Session;
use App\Services\AvailabilityService;
use App\Support\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class MarketplaceController extends Controller
{
    /**
     * All approved + listed notaries by default; search/filter is optional.
     *
     * The platform's own system-native notary is listed alongside the partners —
     * clients may book it directly instead of only reaching it through fallback.
     * A profile with no active service has no price to quote and cannot be booked,
     * so it is left out rather than shown as a dead end.
     */
    public function index(NotarizationRequest $request, Request $http): View
    {
        $this->authorizeOwner($request);

        $query = NotaryProfile::listed()
            ->whereHas('services', fn ($q) => $q->where('active', true))
            ->with(['user', 'services' => fn ($q) => $q->where('active', true)]);

        // Optional search by name
        if ($term = trim((string) $http->query('q'))) {
            $query->whereHas('user', fn ($q) => $q->where('full_name', 'like', "%{$term}%"));
        }

        // Optional filter by specialty
        if ($specialty = $http->query('specialty')) {
            $query->whereJsonContains('specialties', $specialty);
        }

        $notaries = $query->get();

        return view('client.marketplace.index', [
            'request'   => $request,
            'notaries'  => $notaries,
            'q'         => $http->query('q'),
            'specialty' => $http->query('specialty'),
        ]);
    }

    /** A notary's full profile + services + available slots. */
    public function show(NotarizationRequest $request, NotaryProfile $notary, AvailabilityService $availability): View
    {
        $this->authorizeOwner($request);
        $notary->load(['user', 'services' => fn ($q) => $q->where('active', true)]);

        return view('client.marketplace.show', [
            'request'  => $request,
            'notary'   => $notary,
            'slots'    => $availability->slotsFor($notary),
        ]);
    }

    /**
     * Select a notary + service and, optionally, a time slot; create the
     * (tentative) session.
     *
     * Fixing a date and time is deliberately optional — most clients want the
     * job done as soon as the notary is free, and forcing a slot at this point
     * was the main drop-off in the booking form. With no slot the session is
     * still created (everything downstream hangs off it) but with null
     * scheduled times, which every view renders as "as soon as possible".
     */
    public function select(NotarizationRequest $request, Request $http): RedirectResponse
    {
        $this->authorizeOwner($request);

        $validated = $http->validate([
            'notary_id'  => ['required', 'exists:notary_profiles,id'],
            'service_id' => ['required', 'exists:notary_services,id'],
            'slot_start' => ['nullable', 'date', 'after:now'],
        ]);

        $notary = NotaryProfile::listed()->findOrFail($validated['notary_id']);
        $service = NotaryService::where('notary_profile_id', $notary->id)
            ->findOrFail($validated['service_id']);

        $start = ! empty($validated['slot_start']) ? Carbon::parse($validated['slot_start']) : null;
        $end = $start?->copy()->addMinutes(
            $service->estimated_duration_minutes ?: config('nvn.session_slot_minutes')
        );

        $request->update([
            'notary_id'  => $notary->id,
            'service_id' => $service->id,
        ]);

        // Tentative session — confirmed once payment clears (Phase 5).
        Session::updateOrCreate(
            ['request_id' => $request->id],
            [
                'scheduled_start_at' => $start,
                'scheduled_end_at'   => $end,
                'status'             => 'scheduled',
            ],
        );

        AuditLogger::record('request.notary_selected', 'notarization_request', $request->id, [
            'notary_id'  => $notary->id,
            'service_id' => $service->id,
            'scheduled'  => $start?->toIso8601String(),
        ], Auth::id());

        return redirect()->route('client.request.review', ['request' => $request->id]);
    }

    private function authorizeOwner(NotarizationRequest $request): void
    {
        abort_unless($request->client_id === Auth::id(), 403);
        abort_if(
            ! in_array($request->status, [RequestStatus::Draft, RequestStatus::Submitted], true),
            403,
            'This request can no longer be edited.'
        );
    }
}
