<?php

namespace App\Http\Controllers;

use App\Models\NotaryProfile;
use App\Models\Organization;
use App\Support\OrganizationPricing;
use App\Support\OrganizationReferral;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationLandingController extends Controller
{
    public function __construct(private OrganizationPricing $pricing) {}

    /**
     * The page a partner body hands to its applicants.
     *
     * Its one job is to set the cookie and then get out of the way, so both
     * buttons — sign in, create an account — lead into the ordinary flow.
     * Rebuilding registration or intake behind a second door would mean two
     * versions of both to keep in step.
     *
     * A body that is pending, paused, rejected or deleted 404s. Its link may
     * already be printed on something, and the honest answer at that point is
     * that there is no such page, not a page that quietly charges the public
     * price under a government body's name.
     */
    public function show(string $slug, Request $http): View
    {
        $organization = Organization::active()
            ->with('prices')
            ->where('slug', $slug)
            ->firstOrFail();

        OrganizationReferral::remember($organization, $http);

        // The platform's own notary does all of this body's work, so its
        // categories are the ones to quote. Its prices are not: those are the
        // public ones, and this body has its own.
        $notary = NotaryProfile::systemNative()
            ->with(['services' => fn ($q) => $q->where('active', true)->orderBy('service_type')])
            ->first();

        $services = $notary?->services ?? collect();

        return view('public.organization-landing', [
            'organization' => $organization,
            'services'     => $services,
            'quotes'       => $services->mapWithKeys(fn ($service) => [
                $service->id => $this->pricing->displayUnitPrice($organization, $service, 'NGN'),
            ]),
        ]);
    }

    /** /o/{slug} — the short form, for a letter or a poster. */
    public function short(string $slug): RedirectResponse
    {
        return redirect()->route('organization.landing', $slug);
    }

    /** Somewhere to type a code that arrived by telephone or on paper. */
    public function code(): View
    {
        return view('public.organization-code');
    }

    /** Turn a typed code into the body's own landing page. */
    public function redeem(Request $http): RedirectResponse
    {
        $http->validate(['code' => ['required', 'string', 'max:60']]);

        $organization = OrganizationReferral::resolve($http->input('code'));

        if (! $organization) {
            return back()
                ->withInput()
                ->withErrors(['code' => 'We do not recognise that code. Check it with whoever gave it to you.']);
        }

        return redirect()->route('organization.landing', $organization->slug);
    }
}
