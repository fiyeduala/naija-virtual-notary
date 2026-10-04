<?php

namespace Tests\Feature;

use App\Models\NotarizationRequest;
use App\Models\OrganizationPrice;
use App\Support\OrganizationPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * What a partner body's referral is charged.
 *
 * A negotiated rate is a figure, not a discount: ₦25,000 to the public and
 * ₦250,000 to a government body is not a percentage of anything. These tests
 * pin that the figure reaches the fee through the one money method —
 * NotarizationRequest::feeMinor() — and that an ordinary client's request is
 * untouched by any of it.
 */
class OrganizationPricingTest extends TestCase
{
    use RefreshDatabase, Fixtures;

    public function test_the_bodys_default_price_is_what_is_quoted(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary, 2500000);
        $organization = $this->makeOrganization(['default_price_ngn' => 25000000]);

        $this->assertSame(
            25000000,
            app(OrganizationPricing::class)->unitPriceMinor($organization, $service),
        );
    }

    public function test_a_category_override_beats_the_default(): void
    {
        $notary = $this->makeSystemNotary();
        $cheap = $this->makeService($notary, 2500000);
        $dear = $this->makeService($notary, 5000000);

        $organization = $this->makeOrganization(['default_price_ngn' => 25000000]);

        OrganizationPrice::create([
            'organization_id'   => $organization->id,
            'notary_service_id' => $dear->id,
            'price_ngn'         => 90000000,
        ]);

        $organization->load('prices');
        $pricing = app(OrganizationPricing::class);

        $this->assertSame(90000000, $pricing->unitPriceMinor($organization, $dear));
        $this->assertSame(25000000, $pricing->unitPriceMinor($organization, $cheap));
    }

    public function test_a_misconfigured_body_falls_back_to_the_public_price(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary, 2500000);

        // Cannot happen to an approved body — missingBeforeApproval() refuses
        // one without a default price. The fallback exists so that if it ever
        // does, the client is quoted the ordinary figure rather than nothing.
        $organization = $this->makeOrganization(['default_price_ngn' => null]);

        $this->assertSame(
            2500000,
            app(OrganizationPricing::class)->unitPriceMinor($organization, $service),
        );
    }

    public function test_the_frozen_price_is_what_the_fee_reads(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary, 2500000);
        $organization = $this->makeOrganization(['default_price_ngn' => 25000000]);

        $client = $this->makeClient();
        $request = NotarizationRequest::create([
            'reference'       => 'NVN-FREEZE-1',
            'client_id'       => $client->id,
            'notary_id'       => $notary->id,
            'organization_id' => $organization->id,
            'status'          => 'submitted',
            'currency'        => 'NGN',
            'is_offsite'      => false,
        ]);

        app(OrganizationPricing::class)->freezeOnto($request->fresh(), $service);

        $request->fresh()->update(['service_id' => $service->id]);

        $this->assertSame(25000000, $request->fresh()->unit_fee_minor);
        $this->assertSame(25000000, $request->fresh()->feeMinor());
    }

    public function test_the_frozen_price_survives_the_rate_being_renegotiated(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary, 2500000);
        $organization = $this->makeOrganization(['default_price_ngn' => 25000000]);

        $payment = $this->makeOrganizationJob($organization, $notary, 25000000, [
            'service_id' => $service->id,
        ]);

        $organization->update(['default_price_ngn' => 90000000]);

        // The job somebody is halfway through paying for does not move.
        $this->assertSame(25000000, $payment->fresh()->request->feeMinor());
    }

    public function test_a_referral_fee_multiplies_the_bodys_figure_not_the_public_one(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary, 2500000);
        $organization = $this->makeOrganization(['default_price_ngn' => 25000000]);

        $payment = $this->makeOrganizationJob($organization, $notary, 25000000, [
            'service_id' => $service->id,
        ]);

        $request = $payment->fresh()->request;

        // No documents attached, so one billable document — and the unit is
        // the body's figure, which is the bug unitFeeMinor() exists to stop.
        $this->assertSame(25000000, $request->unitFeeMinor());
        $this->assertSame(1, $request->billableDocumentCount());
        $this->assertSame(25000000, $request->feeMinor());
    }

    public function test_an_ordinary_clients_request_is_priced_exactly_as_before(): void
    {
        $notary = $this->makeNotary();
        $service = $this->makeService($notary, 2500000);

        $client = $this->makeClient();
        $request = NotarizationRequest::create([
            'reference'  => 'NVN-PLAIN-1',
            'client_id'  => $client->id,
            'notary_id'  => $notary->id,
            'service_id' => $service->id,
            'status'     => 'submitted',
            'currency'   => 'NGN',
            'is_offsite' => false,
        ]);

        $this->assertFalse($request->fromOrganization());
        $this->assertNull($request->unit_fee_minor);
        $this->assertSame(2500000, $request->feeMinor());

        // And freezing is a no-op on it, so no call site needs to branch.
        app(OrganizationPricing::class)->freezeOnto($request, $service);
        $this->assertNull($request->fresh()->unit_fee_minor);
    }

    public function test_quotes_for_gives_public_prices_on_ordinary_work(): void
    {
        $notary = $this->makeNotary();
        $one = $this->makeService($notary, 2500000);
        $two = $this->makeService($notary, 5000000);

        $client = $this->makeClient();
        $request = NotarizationRequest::create([
            'reference'  => 'NVN-PLAIN-2',
            'client_id'  => $client->id,
            'notary_id'  => $notary->id,
            'status'     => 'submitted',
            'currency'   => 'NGN',
            'is_offsite' => false,
        ]);

        $quotes = app(OrganizationPricing::class)->quotesFor($request, [$one, $two]);

        $this->assertSame(2500000, $quotes[$one->id]);
        $this->assertSame(5000000, $quotes[$two->id]);
    }

    public function test_quotes_for_gives_the_bodys_prices_on_a_referral(): void
    {
        $notary = $this->makeSystemNotary();
        $one = $this->makeService($notary, 2500000);
        $two = $this->makeService($notary, 5000000);

        $organization = $this->makeOrganization(['default_price_ngn' => 25000000]);

        OrganizationPrice::create([
            'organization_id'   => $organization->id,
            'notary_service_id' => $two->id,
            'price_ngn'         => 90000000,
        ]);

        $client = $this->makeClient();
        $request = NotarizationRequest::create([
            'reference'       => 'NVN-ORG-2',
            'client_id'       => $client->id,
            'notary_id'       => $notary->id,
            'organization_id' => $organization->id,
            'status'          => 'submitted',
            'currency'        => 'NGN',
            'is_offsite'      => false,
        ]);

        $quotes = app(OrganizationPricing::class)->quotesFor($request, [$one, $two]);

        $this->assertSame(25000000, $quotes[$one->id]);
        $this->assertSame(90000000, $quotes[$two->id]);
    }
}
