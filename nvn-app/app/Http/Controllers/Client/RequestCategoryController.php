<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Models\NotaryService;
use App\Services\RequestCategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The client's side of a wrong-category query: re-pick, and pay only the gap.
 *
 * Kept out of MarketplaceController on purpose. That controller's
 * authorizeOwner() refuses anything past Submitted, which is exactly the state
 * every request that lands here is in — this one has already been paid for.
 * Only the service moves; the notary of record does not, because their seal is
 * what the client booked and their price list is what the new fee comes from.
 */
class RequestCategoryController extends Controller
{
    public function __construct(private RequestCategoryService $categories) {}

    public function show(NotarizationRequest $request): View|RedirectResponse
    {
        $this->authorizeOwner($request);

        if (! $request->isCategoryBlocked()) {
            return redirect()->route('client.dashboard')
                ->with('status', 'There is nothing to change on ' . $request->reference . '.');
        }

        $request->load('service', 'categorySuggestedService', 'categoryQueriedBy', 'notary.user', 'documents');

        return view('client.request.category', [
            'request'  => $request,
            'services' => $this->choices($request),
        ]);
    }

    /** The client picks a category. Anything still owed goes to checkout. */
    public function update(NotarizationRequest $request, Request $http): RedirectResponse
    {
        $this->authorizeOwner($request);

        if (! $request->hasOpenCategoryQuery()) {
            return redirect()->route('client.dashboard')
                ->with('status', 'That has already been answered.');
        }

        $validated = $http->validate([
            'service_id' => ['required', 'integer'],
        ]);

        // Validated against the notary's own list rather than the services
        // table, so a hand-edited form cannot book this notary at another
        // notary's price.
        $chosen = $this->choices($request)->firstWhere('id', (int) $validated['service_id']);

        if (! $chosen) {
            return back()->withErrors(['service_id' => 'Please choose one of the categories listed.']);
        }

        $balance = $this->categories->resolve($request, $chosen, Auth::user());

        // Back to the same page, which stays reachable while a difference is
        // owed and turns into the pay-the-difference screen. Checkout itself is
        // a POST, so there is nothing to redirect to — the button on that page
        // posts to the existing request-fee route, which now charges the
        // balance rather than the whole fee.
        if ($balance > 0) {
            return redirect()->route('client.request.category.show', $request)->with(
                'status',
                'Filed as ' . $chosen->service_type . '. One last step below.',
            );
        }

        return redirect()->route('client.dashboard')->with(
            'status',
            $request->reference . ' is now filed as ' . $chosen->service_type
                . '. There is nothing more to pay — your notary has it back.',
        );
    }

    /**
     * What this client may choose from: the assigned notary's active services.
     *
     * @return \Illuminate\Support\Collection<int, NotaryService>
     */
    private function choices(NotarizationRequest $request): \Illuminate\Support\Collection
    {
        if (! $request->notary_id) {
            return collect();
        }

        return NotaryService::where('notary_profile_id', $request->notary_id)
            ->where('active', true)
            ->orderBy('service_type')
            ->get();
    }

    private function authorizeOwner(NotarizationRequest $request): void
    {
        abort_unless($request->client_id === Auth::id(), 403);
    }
}
