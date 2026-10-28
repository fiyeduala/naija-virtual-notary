<?php

namespace Tests\Feature;

use App\Models\NotaryBankDetail;
use App\Models\NotaryProfile;
use App\Models\PlatformSetting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * The partner walkthrough, and the two things about it that can quietly rot.
 *
 * The page is prose plus screenshots, so most of it cannot be asserted on and
 * is not worth pretending otherwise. What is worth holding down is the part
 * that is live: the checklist is computed from the signed-in notary's own
 * profile, the prices are read from where the checkout reads them, and the
 * figures are files on disk that a deploy can forget to copy.
 */
class NotaryGuideTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    /** Settings are memoised; leave none behind for the next test. */
    protected function tearDown(): void
    {
        Settings::flush();

        parent::tearDown();
    }

    // ───────────────────────────── Who may read it ─────────────────────────

    public function test_a_notary_can_open_the_guide(): void
    {
        $notary = $this->makeNotary();

        $this->actingAs($notary->user)
            ->get(route('notary.guide'))
            ->assertOk()
            ->assertSee('Your dashboard');
    }

    /**
     * The admin is at the same desk working the same screens, so sending them
     * somewhere else to read how it works would mean writing it twice.
     */
    public function test_an_admin_can_open_the_guide(): void
    {
        $admin = $this->makeClient(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('notary.guide'))
            ->assertOk();
    }

    public function test_a_client_cannot_open_the_guide(): void
    {
        $this->actingAs($this->makeClient())
            ->get(route('notary.guide'))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('notary.guide'))
            ->assertRedirect(route('login'));
    }

    // ────────────────────────────── The checklist ──────────────────────────

    /**
     * Day one: approved and nothing else. Every row is open, and the count in
     * the heading is the number of them.
     */
    public function test_a_new_notary_sees_every_step_outstanding(): void
    {
        $notary = $this->makeNotary([
            'public_listing_enabled' => false,
            'membership_expires_at'  => null,
        ]);

        $this->actingAs($notary->user)
            ->get(route('notary.guide'))
            ->assertOk()
            ->assertSee('Where you are right now')
            ->assertSee('5 things still to do', false)
            ->assertSee('Membership fee paid')
            ->assertSee('Signature, stamp and seal uploaded')
            ->assertSee('Payout account added')
            ->assertSee('At least one service priced');
    }

    /** The same page to a notary who has finished: no outstanding count at all. */
    public function test_a_fully_set_up_notary_sees_the_list_cleared(): void
    {
        $notary = $this->readyNotary();

        $this->actingAs($notary->user)
            ->get(route('notary.guide'))
            ->assertOk()
            ->assertSee('You are all set up')
            ->assertDontSee('still to do', false);
    }

    /**
     * Each blocker is read through the profile's own methods, so clearing one
     * and only one has to move exactly one row.
     */
    public function test_uploading_the_marks_closes_only_that_step(): void
    {
        $notary = $this->makeNotary([
            'public_listing_enabled' => false,
            'membership_expires_at'  => null,
        ]);
        $this->giveSealingAssets($notary);

        $this->actingAs($notary->user)
            ->get(route('notary.guide'))
            ->assertOk()
            ->assertSee('4 things still to do', false);
    }

    /**
     * The platform's own profile pays nothing, cannot lapse and is never
     * listed, so every row would be a yes that means nothing. It gets none.
     */
    public function test_the_system_native_profile_gets_no_checklist(): void
    {
        $desk = $this->makeSystemNotary();

        $this->actingAs($desk->user)
            ->get(route('notary.guide'))
            ->assertOk()
            ->assertDontSee('Where you are right now')
            ->assertDontSee('Membership fee paid');
    }

    /** An admin with no notary profile of their own is in the same position. */
    public function test_an_admin_without_a_profile_gets_no_checklist(): void
    {
        $admin = $this->makeClient(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('notary.guide'))
            ->assertOk()
            ->assertDontSee('Membership fee paid');
    }

    // ──────────────────────────────── The money ────────────────────────────

    /**
     * The guide quotes the fee the checkout charges, not a figure typed into a
     * blade — which is the whole reason the controller reads PlatformSetting.
     */
    public function test_the_fee_quoted_is_the_one_in_settings(): void
    {
        PlatformSetting::set('onboarding_fee_ngn', 4500000);
        Settings::flush();

        $notary = $this->makeNotary();

        $this->actingAs($notary->user)
            ->get(route('notary.guide'))
            ->assertOk()
            ->assertSee('₦45,000.00 a year', false);
    }

    /** Likewise the split: the notary's own rate, not the platform default. */
    public function test_the_commission_quoted_is_the_notarys_own_rate(): void
    {
        $notary = $this->makeNotary(['commission_rate' => 35]);

        $this->actingAs($notary->user)
            ->get(route('notary.guide'))
            ->assertOk()
            ->assertSee('35%', false);
    }

    // ──────────────────────────────── The figures ──────────────────────────

    /**
     * Every screenshot the page asks for is a file in the repository.
     *
     * The partial hides a figure whose file is missing rather than showing a
     * broken image, which is right for a half-finished deploy and wrong as a
     * silent habit — without this test a figure could disappear from the live
     * page and nothing would say so.
     */
    public function test_every_figure_the_guide_asks_for_exists(): void
    {
        $blade = file_get_contents(resource_path('views/notary/guide.blade.php'));

        preg_match_all("/'shot' => '([a-z0-9-]+)'/", $blade, $found);

        $this->assertNotEmpty($found[1], 'The guide references no screenshots at all.');

        foreach ($found[1] as $shot) {
            $this->assertFileExists(
                public_path('images/guide/' . $shot . '.jpg'),
                "The guide asks for the '{$shot}' screenshot and it is not in the repository.",
            );
        }
    }

    // ──────────────────────────────── Helpers ──────────────────────────────

    /** A partner with all four blockers cleared and a live listing. */
    private function readyNotary(): NotaryProfile
    {
        $notary = $this->makeNotary();

        $this->giveSealingAssets($notary);
        $this->makeService($notary);

        NotaryBankDetail::create([
            'notary_profile_id' => $notary->id,
            'bank_name'         => 'Test Bank',
            'bank_code'         => '058',
            'account_number'    => '0123456789',
            'account_name'      => $notary->user->full_name,
        ]);

        return $notary->fresh();
    }
}
