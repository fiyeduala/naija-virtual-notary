<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\Payout;
use App\Services\OrganizationPayoutService;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * What a partner body earns, and the two ledgers that must not touch.
 *
 * The share comes out of the platform's own fee, never a notary's, because
 * organization work is sealed by the platform's own profile. Two columns keep
 * the books apart: `payments.payout_id` is the notary ledger and
 * `payments.organization_payout_id` is this one, and neither run can see the
 * other's claim.
 *
 * The test that matters most here is the price_only one. PayoutService bails on
 * *no payments*, not on a zero amount — so a body that earns nothing, if it
 * were modelled as a 0% rate rather than as a different arrangement, would
 * generate an empty payout that claimed every one of its fees and made them
 * unreachable afterwards.
 */
class OrganizationCommissionTest extends TestCase
{
    use RefreshDatabase, Fixtures;

    public function test_the_share_accrues_on_completed_naira_work(): void
    {
        $notary = $this->makeSystemNotary();
        $organization = $this->makeOrganization(['commission_rate' => 20]);

        $this->makeOrganizationJob($organization, $notary, 25000000);

        $this->assertSame(5000000, app(OrganizationPayoutService::class)->owed($organization));
    }

    public function test_work_that_is_not_finished_earns_nothing_yet(): void
    {
        $notary = $this->makeSystemNotary();
        $organization = $this->makeOrganization(['commission_rate' => 20]);

        $this->makeOrganizationJob($organization, $notary, 25000000, [
            'status'       => 'paid',
            'completed_at' => null,
        ]);

        // Completion earns the cut, not payment — the same rule partners get.
        $this->assertSame(0, app(OrganizationPayoutService::class)->owed($organization));
    }

    public function test_offsite_and_dollar_work_are_excluded(): void
    {
        $notary = $this->makeSystemNotary();
        $organization = $this->makeOrganization(['commission_rate' => 20]);

        $this->makeOrganizationJob($organization, $notary, 25000000, ['is_offsite' => true]);
        $this->makeOrganizationJob($organization, $notary, 25000000, ['currency' => 'USD'],
            ['currency' => 'USD']);

        // Offsite is the notary's own outside work. USD is excluded because a
        // transfer settles in naira, exactly as it is for notaries.
        $this->assertSame(0, app(OrganizationPayoutService::class)->owed($organization));
    }

    public function test_the_frozen_rate_is_what_is_paid_not_the_current_one(): void
    {
        $notary = $this->makeSystemNotary();
        $organization = $this->makeOrganization(['commission_rate' => 20]);

        $this->makeOrganizationJob($organization, $notary, 25000000);

        $organization->update(['commission_rate' => 50]);

        // A rate renegotiated this month must not change what was earned last
        // month. 20% of what came in, not 50%.
        $this->assertSame(5000000, app(OrganizationPayoutService::class)->owed($organization->fresh()));
    }

    public function test_generating_attaches_the_payments_and_a_second_run_finds_nothing(): void
    {
        $notary = $this->makeSystemNotary();
        $organization = $this->makeOrganization(['commission_rate' => 20]);

        $payment = $this->makeOrganizationJob($organization, $notary, 25000000);

        $payouts = app(OrganizationPayoutService::class);

        $payout = $payouts->generateFor($organization);

        $this->assertInstanceOf(OrganizationPayout::class, $payout);
        $this->assertSame(5000000, $payout->amount);
        $this->assertSame(25000000, $payout->gross_amount);
        $this->assertSame($payout->id, $payment->fresh()->organization_payout_id);

        // The attachment is the ledger, so there is nothing left owed and a
        // second run creates nothing.
        $this->assertSame(0, $payouts->owed($organization->fresh()));
        $this->assertNull($payouts->generateFor($organization->fresh()));
        $this->assertSame(1, OrganizationPayout::count());
    }

    public function test_a_body_charged_a_rate_only_produces_no_payout_at_all(): void
    {
        $notary = $this->makeSystemNotary();
        $organization = $this->makeOrganization([
            'arrangement'     => Organization::PRICE_ONLY,
            'commission_rate' => 0,
        ]);

        $payment = $this->makeOrganizationJob($organization, $notary, 25000000);

        $payouts = app(OrganizationPayoutService::class);

        $this->assertNull($payouts->generateFor($organization));
        $this->assertSame(0, OrganizationPayout::count());

        // And — the whole point — its fees are left unattached rather than
        // swallowed into an empty payout nobody can reach afterwards.
        $this->assertNull($payment->fresh()->organization_payout_id);

        $this->assertTrue($payouts->generateAll()->isEmpty());
        $this->assertSame(0, OrganizationPayout::count());
    }

    public function test_a_zero_rate_on_a_commission_body_also_produces_nothing(): void
    {
        $notary = $this->makeSystemNotary();
        $organization = $this->makeOrganization(['commission_rate' => 0]);

        $payment = $this->makeOrganizationJob($organization, $notary, 25000000);

        $this->assertNull(app(OrganizationPayoutService::class)->generateFor($organization));
        $this->assertNull($payment->fresh()->organization_payout_id);
    }

    public function test_organization_work_generates_no_notary_payout(): void
    {
        $notary = $this->makeSystemNotary();
        $organization = $this->makeOrganization(['commission_rate' => 20]);

        $this->makeOrganizationJob($organization, $notary, 25000000);

        // The notary is the platform's own profile, so there is nobody to pay.
        // PayoutService::generateAll() excludes is_system_native, and that is
        // the only thing that moves money — asserted out loud here rather than
        // left assumed, because the organization's cut comes out of the
        // platform's fee on the understanding that no notary is owed any of it.
        $this->assertTrue(app(PayoutService::class)->generateAll()->isEmpty());
        $this->assertSame(0, Payout::count());

        // owed() is deliberately not asserted to be zero. It is arithmetic on
        // a commission rate and will happily answer for the house profile; it
        // is the run above, and PayoutResource's own summary, that decide who
        // is actually asked.
    }

    public function test_the_two_ledgers_claim_their_own_column(): void
    {
        $partner = $this->makeNotary();
        $house = $this->makeSystemNotary();
        $organization = $this->makeOrganization(['commission_rate' => 20]);

        $partnerPayment = $this->makeCompletedJob($partner, 4000000);
        $organizationPayment = $this->makeOrganizationJob($organization, $house, 25000000);

        $notaryPayout = app(PayoutService::class)->generateFor($partner);
        $organizationPayout = app(OrganizationPayoutService::class)->generateFor($organization);

        $this->assertNotNull($notaryPayout);
        $this->assertNotNull($organizationPayout);

        // Each run claimed only its own work, in its own column.
        $this->assertSame($notaryPayout->id, $partnerPayment->fresh()->payout_id);
        $this->assertNull($partnerPayment->fresh()->organization_payout_id);

        $this->assertSame($organizationPayout->id, $organizationPayment->fresh()->organization_payout_id);
        $this->assertNull($organizationPayment->fresh()->payout_id);
    }

    public function test_cancelling_a_payout_makes_the_share_owed_again(): void
    {
        $notary = $this->makeSystemNotary();
        $organization = $this->makeOrganization(['commission_rate' => 20]);

        $payment = $this->makeOrganizationJob($organization, $notary, 25000000);

        $payouts = app(OrganizationPayoutService::class);
        $payout = $payouts->generateFor($organization);

        [$ok] = $payouts->cancel($payout, 'wrong period');

        $this->assertTrue($ok);
        $this->assertNull($payment->fresh()->organization_payout_id);
        $this->assertSame(5000000, $payouts->owed($organization->fresh()));
    }

    public function test_settling_offline_records_how_it_was_paid(): void
    {
        $notary = $this->makeSystemNotary();
        $organization = $this->makeOrganization(['commission_rate' => 20]);

        $this->makeOrganizationJob($organization, $notary, 25000000);

        $payouts = app(OrganizationPayoutService::class);
        $payout = $payouts->generateFor($organization);

        [$ok] = $payouts->settleOffline($payout, [
            'method'    => 'bank_transfer',
            'paid_at'   => now(),
            'reference' => 'BANK-REF-1',
        ]);

        $payout = $payout->fresh();

        $this->assertTrue($ok);
        $this->assertTrue($payout->isPaid());
        $this->assertSame('bank_transfer', $payout->settlement_method);
        $this->assertSame('BANK-REF-1', $payout->settlement_reference);
        $this->assertSame(5000000, $payouts->paidOut($organization->fresh()));
    }
}
