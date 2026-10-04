<?php

namespace Tests\Feature;

use App\Models\NotarizationRequest;
use App\Models\Organization;
use App\Support\OrganizationReferral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Who gets credited for a referral, and who never does.
 *
 * Attribution is the foundation the price and the commission both stand on: a
 * request with the wrong organization_id is quoted the wrong figure and earns
 * the wrong body money. So the cases that must resolve to *nobody* get as much
 * attention here as the ones that must resolve to somebody.
 */
class OrganizationReferralTest extends TestCase
{
    use RefreshDatabase, Fixtures;

    public function test_a_landing_page_names_the_body_and_sets_the_cookie(): void
    {
        $notary = $this->makeSystemNotary();
        $this->makeService($notary);
        $organization = $this->makeOrganization(['name' => 'Federal Lands Bureau']);

        $this->get(route('organization.landing', $organization->slug))
            ->assertOk()
            ->assertSee('Federal Lands Bureau')
            ->assertCookie(OrganizationReferral::COOKIE, $organization->code);
    }

    public function test_the_short_link_redirects_to_the_landing_page(): void
    {
        $organization = $this->makeOrganization();

        $this->get(route('organization.short', $organization->slug))
            ->assertRedirect(route('organization.landing', $organization->slug));
    }

    public function test_a_paused_body_is_not_reachable_and_attributes_nothing(): void
    {
        $organization = $this->makeOrganization(['status' => 'paused']);

        $this->get(route('organization.landing', $organization->slug))->assertNotFound();

        // And a cookie already in somebody's browser stops counting the moment
        // the body is switched off — a stale cookie cannot be recalled.
        $this->assertNull(OrganizationReferral::resolve($organization->code));
    }

    public function test_a_pending_body_is_inert(): void
    {
        $organization = $this->makeOrganization(['status' => 'pending']);

        $this->get(route('organization.landing', $organization->slug))->assertNotFound();
        $this->assertNull(OrganizationReferral::resolve($organization->code));
    }

    public function test_a_code_typed_in_by_hand_reaches_the_same_page(): void
    {
        $organization = $this->makeOrganization();

        $this->post(route('organization.redeem'), ['code' => strtolower($organization->code)])
            ->assertRedirect(route('organization.landing', $organization->slug));
    }

    public function test_registering_through_a_link_stamps_the_account_once(): void
    {
        $first = $this->makeOrganization(['code' => 'FIRST', 'slug' => 'first']);
        $second = $this->makeOrganization(['code' => 'SECOND', 'slug' => 'second']);

        $client = $this->makeClient();

        $request = \Illuminate\Http\Request::create('/', 'GET', ['org' => 'FIRST']);
        $request->setLaravelSession(app('session.store'));
        OrganizationReferral::stampUser($client, $request);

        $this->assertSame($first->id, $client->fresh()->referred_by_organization_id);

        // First touch. Somebody introduced by an embassy who later opens a law
        // firm's link is still the embassy's referral.
        $later = \Illuminate\Http\Request::create('/', 'GET', ['org' => 'SECOND']);
        $later->setLaravelSession(app('session.store'));
        OrganizationReferral::stampUser($client->fresh(), $later);

        $this->assertSame($first->id, $client->fresh()->referred_by_organization_id);
        $this->assertSame(0, $second->requests()->count());
    }

    public function test_a_notary_opening_the_link_is_never_attributed(): void
    {
        $organization = $this->makeOrganization();
        $notary = $this->makeNotary();

        $request = \Illuminate\Http\Request::create('/', 'GET', ['org' => $organization->code]);
        $request->setLaravelSession(app('session.store'));

        // The cookie resolves fine — it is the person that disqualifies it.
        $this->assertNotNull(OrganizationReferral::capture($request));
        $this->assertNull(OrganizationReferral::forRequest($notary->user, $request));
    }

    public function test_a_client_outside_the_window_with_no_cookie_is_not_attributed(): void
    {
        $organization = $this->makeOrganization(['attribution_days' => 30]);

        $client = $this->makeClient();
        $client->forceFill([
            'referred_by_organization_id' => $organization->id,
            'organization_referred_at'    => now()->subDays(45),
        ])->save();

        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->setLaravelSession(app('session.store'));

        $this->assertNull(OrganizationReferral::forRequest($client->fresh(), $request));

        // Inside the window, the same account is still theirs.
        $client->forceFill(['organization_referred_at' => now()->subDays(5)])->save();

        $this->assertSame(
            $organization->id,
            OrganizationReferral::forRequest($client->fresh(), $request)?->id,
        );
    }

    public function test_an_attribution_window_of_zero_means_forever(): void
    {
        $organization = $this->makeOrganization(['attribution_days' => 0]);

        $client = $this->makeClient();
        $client->forceFill([
            'referred_by_organization_id' => $organization->id,
            'organization_referred_at'    => now()->subYears(3),
        ])->save();

        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->setLaravelSession(app('session.store'));

        $this->assertSame(
            $organization->id,
            OrganizationReferral::forRequest($client->fresh(), $request)?->id,
        );
    }

    public function test_an_offsite_job_is_never_organization_work(): void
    {
        $organization = $this->makeOrganization();
        $notary = $this->makeNotary();

        $this->makeCompletedJob($notary, 5000000, ['is_offsite' => true]);

        $this->assertSame(0, $organization->requests()->count());
        $this->assertSame(0, NotarizationRequest::fromOrganizations()->count());
    }
}
