<?php

namespace Tests\Feature;

use App\Models\NotaryProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Who a client can find in the marketplace.
 *
 * The yearly partner fee buys a place in the listing, so a lapsed membership
 * has to take a partner out of it. The platform's own notary never pays and
 * must never disappear, or clients arrive at a marketplace with nobody in it.
 */
class MembershipListingTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    private function listedIds(): array
    {
        return NotaryProfile::query()->listed()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_an_approved_paid_up_partner_is_listed(): void
    {
        $notary = $this->makeNotary();

        $this->assertContains($notary->id, $this->listedIds());
        $this->assertTrue($notary->membershipActive());
    }

    public function test_a_lapsed_partner_drops_out_of_the_listing(): void
    {
        $notary = $this->makeNotary(['membership_expires_at' => now()->subDay()]);

        $this->assertNotContains($notary->id, $this->listedIds());
        $this->assertFalse($notary->fresh()->membershipActive());
        $this->assertTrue($notary->fresh()->membershipLapsed());
    }

    /** A partner who never paid has not lapsed — but is not listed either. */
    public function test_a_partner_who_never_paid_is_not_listed(): void
    {
        $notary = $this->makeNotary(['membership_expires_at' => null]);

        $this->assertNotContains($notary->id, $this->listedIds());
        $this->assertFalse($notary->fresh()->membershipActive());
        $this->assertFalse($notary->fresh()->membershipLapsed());
    }

    /** Renewing puts them straight back. */
    public function test_renewing_restores_the_listing(): void
    {
        $notary = $this->makeNotary(['membership_expires_at' => now()->subDay()]);
        $notary->update(['membership_expires_at' => now()->addYear()]);

        $this->assertContains($notary->id, $this->listedIds());
    }

    /** Nobody has to switch a lapsed partner off; time alone does it. */
    public function test_the_listing_follows_the_clock(): void
    {
        $notary = $this->makeNotary(['membership_expires_at' => now()->addDays(3)]);

        $this->assertContains($notary->id, $this->listedIds());

        $this->travel(4)->days();

        $this->assertNotContains($notary->id, $this->listedIds());
    }

    public function test_the_platforms_own_notary_is_listed_without_a_membership(): void
    {
        $platform = $this->makeNotary(['is_system_native' => true, 'membership_expires_at' => null]);

        $this->assertContains($platform->id, $this->listedIds());
        $this->assertTrue($platform->fresh()->membershipActive());
        $this->assertFalse($platform->fresh()->membershipLapsed());
    }

    /** Paying the fee does not skip review. */
    public function test_an_unapproved_notary_is_not_listed_even_when_paid_up(): void
    {
        $pending = $this->makeNotary(['verification_status' => 'pending']);

        $this->assertNotContains($pending->id, $this->listedIds());
    }

    /** A notary whose listing is switched off stays off. */
    public function test_a_notary_with_listing_switched_off_is_not_listed(): void
    {
        $hidden = $this->makeNotary(['public_listing_enabled' => false]);

        $this->assertNotContains($hidden->id, $this->listedIds());
    }
}
