<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\OrganizationPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * The body's own portal, and the wall around it.
 *
 * Two separate things are pinned here. The first is that the portal works at
 * all without anyone in the office having to read figures down a telephone.
 *
 * The second is the boundary, and it is the more important of the two: a body
 * is a referrer, not a party to the notarization. It sees references and
 * states; it never sees a client's name, email or documents, because the
 * client never agreed to that. The portal also runs on its own guard, so the
 * two session worlds cannot be crossed in either direction — an organization
 * can no more reach a client dashboard than a client can reach a portal.
 */
class OrganizationPortalTest extends TestCase
{
    use RefreshDatabase, Fixtures;

    public function test_a_live_body_can_sign_in_and_is_remembered(): void
    {
        $this->makeSystemNotary();
        $organization = $this->makeOrganization();

        $this->post(route('organization.login.attempt'), [
            'email'    => $organization->email,
            'password' => 'password',
        ])->assertRedirect(route('organization.portal'));

        $this->assertAuthenticatedAs($organization, 'organization');
        $this->assertNotNull($organization->fresh()->last_login_at);
    }

    public function test_a_paused_body_loses_its_portal_and_its_link_together(): void
    {
        $organization = $this->makeOrganization(['status' => 'paused']);

        $this->post(route('organization.login.attempt'), [
            'email'    => $organization->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('organization');

        // "Paused" must not mean two different things depending on which door
        // was tried.
        $this->get(route('organization.landing', $organization->slug))->assertNotFound();
    }

    public function test_a_body_sees_its_own_referrals_and_not_another_body_s(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary);

        $mine = $this->makeOrganization();
        $theirs = $this->makeOrganization();

        $ours = $this->makeOrganizationJob($mine, $notary, 25000000, ['service_id' => $service->id])->request;
        $other = $this->makeOrganizationJob($theirs, $notary, 25000000, ['service_id' => $service->id])->request;

        $this->signIn($mine);

        $this->get(route('organization.portal'))
            ->assertOk()
            ->assertSee($ours->reference)
            ->assertDontSee($other->reference);
    }

    public function test_the_portal_shows_no_client_detail(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary);
        $organization = $this->makeOrganization();

        $request = $this->makeOrganizationJob($organization, $notary, 25000000, [
            'service_id' => $service->id,
        ])->request;

        $client = $request->client;

        $this->signIn($organization);

        $this->get(route('organization.portal'))
            ->assertOk()
            // The reference is the whole of what a referrer is entitled to.
            ->assertSee($request->reference)
            ->assertDontSee($client->full_name)
            ->assertDontSee($client->email);
    }

    public function test_a_body_on_commission_sees_what_it_has_earned(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary);
        $organization = $this->makeOrganization(['commission_rate' => 20]);

        $this->makeOrganizationJob($organization, $notary, 25000000, ['service_id' => $service->id]);

        // 20% of ₦250,000. The same figure the admin screen owes them, read
        // off the same service rather than recomputed in the view.
        $this->assertSame(5000000, app(OrganizationPayoutService::class)->owed($organization));

        $this->signIn($organization);

        $this->get(route('organization.portal'))
            ->assertOk()
            ->assertSee('50,000.00');
    }

    public function test_a_body_charged_a_rate_only_sees_its_orders_and_no_earnings_panel(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary);
        $organization = $this->makeOrganization([
            'arrangement'     => Organization::PRICE_ONLY,
            'commission_rate' => 0,
        ]);

        $request = $this->makeOrganizationJob($organization, $notary, 25000000, [
            'service_id' => $service->id,
        ])->request;

        // A zeroed panel would say nothing true and would only invite the
        // question, so there is no panel at all.
        $this->signIn($organization);

        $this->get(route('organization.portal'))
            ->assertOk()
            ->assertSee($request->reference)
            ->assertDontSee('Commission owed')
            ->assertDontSee('Awaiting payment to you');
    }

    public function test_a_client_or_a_notary_cannot_reach_the_portal(): void
    {
        $this->makeOrganization();

        $this->actingAs($this->makeClient())
            ->get(route('organization.portal'))
            ->assertRedirect(route('organization.login'));

        $this->actingAs($this->makeNotary()->user)
            ->get(route('organization.portal'))
            ->assertRedirect(route('organization.login'));
    }

    public function test_a_body_cannot_reach_the_client_side_of_the_site(): void
    {
        $organization = $this->makeOrganization();

        $this->signIn($organization);

        // Its own guard, so a body holding a portal session is simply not a
        // signed-in user anywhere else on the site — and is sent to the
        // client sign-in page it has no account on, which is the right answer.
        $this->get(route('client.dashboard'))->assertRedirect(route('login'));
        $this->get(route('notary.dashboard'))->assertRedirect(route('login'));
    }

    /**
     * Sign a body in the way a body actually signs in.
     *
     * Through the form, not through actingAs($organization, 'organization').
     * That helper makes the organization guard the *default* one, which never
     * happens in a real request — the default stays `web`, which is exactly
     * why the portal draws its own sign-out button instead of relying on the
     * navbar. A test that moved the default would be testing a site that does
     * not exist, and would hide the thing worth checking.
     */
    private function signIn(Organization $organization): void
    {
        $this->post(route('organization.login.attempt'), [
            'email'    => $organization->email,
            'password' => 'password',
        ])->assertRedirect(route('organization.portal'));
    }

    public function test_signing_out_ends_the_portal_session(): void
    {
        $organization = $this->makeOrganization();

        $this->signIn($organization);

        $this->post(route('organization.logout'))
            ->assertRedirect(route('organization.login'));

        $this->assertGuest('organization');
    }
}
