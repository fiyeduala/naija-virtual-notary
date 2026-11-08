<?php

namespace Tests\Feature;

use App\Filament\Pages\PeopleSummaryPage;
use App\Models\NotaryProfile;
use App\Support\PeopleSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * The people summary counts rows, and only an administrator may read it.
 *
 * Two things are worth a test here. The first is the gate: the page carries
 * account emails and what every notary is owed, so a client or a notary
 * reaching it would be a disclosure, not a cosmetic bug — and Filament asks
 * canAccess() before it serves the page, so the test asks the page directly
 * rather than trusting that the navigation link is hidden.
 *
 * The second is that the figures are derived, not typed. "Able to seal" is the
 * one that can silently drift: it has no column behind it, and a summary that
 * disagreed with the gate which stops a job finishing would send an owner to
 * promise work that cannot be delivered.
 */
class PeopleSummaryTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    /**
     * The page itself renders, not just its gate.
     *
     * canAccess() passing proves nothing about the Blade view, and a summary
     * screen is almost all view — a missing array key or a null date would be a
     * 500 that no other test in this file would notice.
     */
    public function test_the_page_renders_for_an_administrator(): void
    {
        $notary = $this->makeNotary();
        $this->giveSealingAssets($notary);
        $this->makeCompletedJob($notary);

        // A notary with nothing uploaded and no membership at all: the row that
        // exercises every "missing" and "never set" branch in the table.
        $this->makeNotary(['membership_expires_at' => null]);
        $this->makeSystemNotary();

        $this->actingAs($this->makeClient(['role' => 'admin']))
            ->get(route('filament.admin.pages.people-summary-page'))
            ->assertOk()
            ->assertSee('People summary')
            ->assertSee('Able to seal');
    }

    public function test_only_an_administrator_can_reach_the_page(): void
    {
        $this->actingAs($this->makeClient());
        $this->assertFalse(PeopleSummaryPage::canAccess(), 'A client could reach the people summary.');

        $this->actingAs($this->makeNotary()->user);
        $this->assertFalse(PeopleSummaryPage::canAccess(), 'A notary could reach the people summary.');

        $this->actingAs($this->makeClient(['role' => 'admin']));
        $this->assertTrue(PeopleSummaryPage::canAccess(), 'An administrator could not reach the people summary.');
    }

    public function test_accounts_are_counted_by_role_and_by_state(): void
    {
        $this->makeClient();
        $this->makeClient();
        $this->makeClient(['email_verified_at' => null]);
        $this->makeClient(['status' => 'suspended']);
        $this->makeClient(['role' => 'admin']);
        $notary = $this->makeNotary();

        $users = PeopleSummary::users();

        $this->assertSame(6, $users['total']);
        $this->assertSame(4, $users['clients']);
        $this->assertSame(1, $users['notaries']);
        $this->assertSame(1, $users['admins']);
        $this->assertSame(1, $users['suspended']);
        $this->assertSame(1, $users['unverified']);

        // Nobody in a fresh database has ever signed in, and that is a figure
        // an owner needs: an account that has never been used is a row.
        $this->assertSame(6, $users['never_signed_in']);
        $this->assertSame(0, $users['signed_in']);

        $notary->user->update(['last_login_at' => now()]);
        $this->assertSame(1, PeopleSummary::users()['signed_in']);
    }

    /** A soft-deleted account leaves the live counts and appears as archived. */
    public function test_an_archived_account_is_reported_separately(): void
    {
        $client = $this->makeClient();
        $this->makeClient();

        $client->delete();

        $users = PeopleSummary::users();

        $this->assertSame(1, $users['total']);
        $this->assertSame(1, $users['archived']);
    }

    public function test_able_to_seal_agrees_with_the_sealing_gate(): void
    {
        $ready    = $this->makeNotary();
        $notReady = $this->makeNotary();
        $this->giveSealingAssets($ready);

        $notaries = PeopleSummary::notaries();

        $this->assertSame(2, $notaries['total']);
        $this->assertSame(1, $notaries['can_seal']);
        $this->assertSame(1, $notaries['cannot_seal']);

        // The same question, asked of the models themselves.
        $this->assertTrue($ready->fresh()->canSeal());
        $this->assertFalse($notReady->fresh()->canSeal());
    }

    public function test_a_lapsed_membership_leaves_the_listed_count(): void
    {
        $this->makeNotary();
        $lapsed = $this->makeNotary(['membership_expires_at' => now()->subDay()]);

        $notaries = PeopleSummary::notaries();

        $this->assertSame(2, $notaries['total']);
        $this->assertSame(1, $notaries['listed'], 'A lapsed notary was still counted as listed.');
        $this->assertSame(1, $notaries['membership_lapsed']);
        $this->assertSame(1, $notaries['membership_live']);

        $this->assertTrue($lapsed->fresh()->membershipLapsed());
    }

    /**
     * The per-notary rows name the missing marks rather than only answering no,
     * and they put the busiest notary first.
     */
    public function test_notary_rows_are_ordered_by_completed_work(): void
    {
        $quiet = $this->makeNotary();
        $busy  = $this->makeNotary();
        $this->giveSealingAssets($busy);

        $this->makeCompletedJob($busy);
        $this->makeCompletedJob($busy);

        $rows = PeopleSummary::notaryRows();

        $this->assertSame($busy->id, $rows->first()['id'], 'The busiest notary was not first.');
        $this->assertSame(2, $rows->first()['jobs']);
        $this->assertTrue($rows->first()['can_seal']);

        $quietRow = $rows->firstWhere('id', $quiet->id);
        $this->assertSame(0, $quietRow['jobs']);
        $this->assertFalse($quietRow['can_seal']);
        $this->assertSame(NotaryProfile::SEALING_ASSETS, $quietRow['missing']);
    }

    /**
     * Owed is the payout service's figure, not a second sum.
     *
     * If this ever diverges, the summary is the thing that is wrong — the
     * service is what actually hands money over.
     */
    public function test_owed_matches_the_payout_service(): void
    {
        $notary = $this->makeNotary();
        $this->makeCompletedJob($notary);

        $row      = PeopleSummary::notaryRows()->firstWhere('id', $notary->id);
        $expected = app(\App\Services\PayoutService::class)->owed($notary->fresh());

        $this->assertSame($expected, $row['owed']);
        $this->assertGreaterThan(0, $row['owed'], 'A completed, paid job owed the notary nothing.');
    }
}
