<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\NotarizationRequest;
use App\Models\Payout;
use App\Services\PayoutService;
use App\Support\VisitorCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Which currency a visitor is quoted in, and who gets to decide it.
 *
 * Three separate questions, and the tests are grouped by them:
 *
 *  - WHERE is the visitor? Cloudflare's CF-IPCountry header answers it, but
 *    only when the request genuinely came through Cloudflare. The origin is
 *    reachable by its own address, so an untrusted sender could otherwise pick
 *    which of two price lists to be charged from. That is the test that
 *    matters most here; the rest is behaviour, this one is money.
 *  - WHAT do they want? An explicit choice outranks the header, for the
 *    Nigerian abroad with a naira card and the Lagos client with a dollar one.
 *  - CAN we take it? Dollars only once Paystack is known to accept them on
 *    this account. Until then dollars are not offered at all, because an
 *    option checkout would refuse is a dead end dressed as a choice.
 */
class VisitorCurrencyTest extends TestCase
{
    use RefreshDatabase, Fixtures;

    /** A Cloudflare edge address, from CloudflareProxies::V4. */
    private const EDGE = '173.245.48.1';

    /** This origin's own address, as a visitor who bypassed Cloudflare sees it. */
    private const DIRECT = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        // The valve, opened only by the tests that are about it. Shut is the
        // production default and the subject of its own tests below.
        config(['nvn.currency.usd_checkout' => true]);

        // Intake writes two files to the private disk and tells the desk by
        // mail. Neither is what these tests are about, and the real disk would
        // leave uploads behind after every run.
        Storage::fake('private');
        Notification::fake();
    }

    /**
     * A complete, valid intake post, with the currency under test.
     *
     * Every field is here because IntakeRequest requires it; a partial payload
     * would fail validation for a reason that has nothing to do with currency
     * and the test would pass while proving nothing. Hard copy off, so no
     * delivery address is needed.
     */
    private function intake(array $overrides = []): array
    {
        return array_merge([
            'first_name'     => 'Ada',
            'last_name'      => 'Obi',
            'email'          => 'ada@example.test',
            'phone'          => '08030000000',
            'document_use'   => 'Proof of address for a visa application.',
            'document'       => UploadedFile::fake()->create('deed.pdf', 40, 'application/pdf'),
            'identification' => UploadedFile::fake()->create('passport.pdf', 20, 'application/pdf'),
            'currency'       => 'NGN',
            'hard_copy'      => 0,
            'consent'        => 1,
        ], $overrides);
    }

    /** The intake form, as seen from a given address with a given country. */
    private function intakeFrom(string $ip, ?string $country)
    {
        $headers = $country === null ? [] : ['CF-IPCountry' => $country];

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get(route('client.request.create'), $headers);
    }

    public function test_a_visitor_in_nigeria_is_quoted_naira(): void
    {
        $this->actingAs($this->makeClient());

        $response = $this->intakeFrom(self::EDGE, 'NG');

        $response->assertOk();
        $response->assertSee('value="NGN" selected', false);
        $response->assertDontSee('outside Nigeria');
    }

    public function test_a_visitor_abroad_is_quoted_dollars(): void
    {
        $this->actingAs($this->makeClient());

        $response = $this->intakeFrom(self::EDGE, 'GB');

        $response->assertOk();
        $response->assertSee('value="USD" selected', false);
        $response->assertSee('You look to be outside Nigeria');
    }

    /**
     * The one that is about money rather than about behaviour.
     *
     * CF-IPCountry is a header, and a header is whatever its sender says it
     * is. The staging subdomain resolves straight to this origin, so anyone
     * who finds that address can send any header they like — and if that
     * header chose the price list, they would be choosing their own price.
     * Honoured only from a Cloudflare address, ignored from anywhere else.
     */
    public function test_a_country_header_from_an_untrusted_address_is_ignored(): void
    {
        $this->actingAs($this->makeClient());

        $response = $this->intakeFrom(self::DIRECT, 'GB');

        $response->assertOk();
        $response->assertSee('value="NGN" selected', false);
        $response->assertDontSee('outside Nigeria');
    }

    public function test_an_unplaceable_visitor_is_quoted_naira(): void
    {
        $this->actingAs($this->makeClient());

        // XX is Cloudflare for "could not place this address" and T1 is a Tor
        // exit node. Neither means "abroad", and naira is the currency the
        // platform can actually collect and pay out in — so an unknown visitor
        // is quoted the one that works rather than the one that might not.
        foreach (['XX', 'T1', null] as $country) {
            $response = $this->intakeFrom(self::EDGE, $country);

            $response->assertOk();
            $response->assertSee('value="NGN" selected', false);
        }
    }

    public function test_detection_can_be_switched_off_entirely(): void
    {
        config(['nvn.currency.by_location' => false]);

        $this->actingAs($this->makeClient());

        $this->intakeFrom(self::EDGE, 'GB')
            ->assertOk()
            ->assertSee('value="NGN" selected', false);
    }

    public function test_an_explicit_choice_beats_the_header(): void
    {
        $client = $this->makeClient();
        $this->actingAs($client);

        // A Nigerian abroad, paying with a naira card. The header says
        // dollars; they say naira; they win, and it sticks for the visit.
        $this->withServerVariables(['REMOTE_ADDR' => self::EDGE])
            ->withSession([VisitorCurrency::SESSION_KEY => 'NGN'])
            ->get(route('client.request.create'), ['CF-IPCountry' => 'GB'])
            ->assertOk()
            ->assertSee('value="NGN" selected', false);
    }

    public function test_choosing_a_currency_at_intake_remembers_it(): void
    {
        $this->actingAs($this->makeClient());

        $this->withServerVariables(['REMOTE_ADDR' => self::EDGE])
            ->post(route('client.request.store'), $this->intake(['currency' => 'USD']))
            ->assertSessionHasNoErrors();

        $this->assertSame('USD', session(VisitorCurrency::SESSION_KEY));
        $this->assertSame('USD', NotarizationRequest::latest('id')->first()->currency);
    }

    /*
    |--------------------------------------------------------------------------
    | The valve
    |--------------------------------------------------------------------------
    */

    public function test_dollars_are_not_offered_while_paystack_cannot_take_them(): void
    {
        config(['nvn.currency.usd_checkout' => false]);

        $this->actingAs($this->makeClient());

        $response = $this->intakeFrom(self::EDGE, 'GB');

        $response->assertOk();
        // No dropdown at all: there is one collectable currency, and a select
        // with a single option only looks broken.
        $response->assertSee('Priced in Nigerian Naira');
        $response->assertDontSee('value="USD"', false);
    }

    public function test_a_posted_dollar_currency_is_refused_rather_than_quietly_changed(): void
    {
        config(['nvn.currency.usd_checkout' => false]);

        $this->actingAs($this->makeClient());

        // Rejected, not silently turned into naira. The figure a client agreed
        // to is the figure they should be charged, so a currency we cannot
        // collect is an error and not a correction made behind their back.
        $this->withServerVariables(['REMOTE_ADDR' => self::EDGE])
            ->post(route('client.request.store'), $this->intake(['currency' => 'USD']))
            ->assertSessionHasErrors('currency');

        $this->assertSame(0, NotarizationRequest::count());
    }

    /**
     * One currency per request, whatever the visitor was quoted.
     *
     * NotarizationRequest::amountPaidMinor() sums payment amounts with no
     * currency filter — correctly, because a request settles in one currency.
     * A row quoted in dollars and part-paid in naira would add cents to kobo
     * and report a balance that is arithmetic nonsense, and a category
     * correction asks for the difference as a second payment, so that is
     * reachable rather than theoretical.
     */
    public function test_a_request_is_never_created_in_a_currency_we_cannot_collect(): void
    {
        config(['nvn.currency.usd_checkout' => false]);

        $this->actingAs($this->makeClient());

        // The session says dollars from an earlier visit when the valve was
        // open; the valve is shut now, and the request must still be naira.
        $this->withServerVariables(['REMOTE_ADDR' => self::EDGE])
            ->withSession([VisitorCurrency::SESSION_KEY => 'USD'])
            ->post(route('client.request.store'), $this->intake(['currency' => 'NGN']))
            ->assertSessionHasNoErrors();

        $this->assertSame('NGN', NotarizationRequest::latest('id')->first()->currency);
    }

    /*
    |--------------------------------------------------------------------------
    | Earned, but not payable from here
    |--------------------------------------------------------------------------
    |
    | Payment::scopePayable() excludes anything that is not naira, and rightly
    | so: a Paystack transfer reaches a Nigerian bank account in naira. What
    | was wrong is that a fee excluded that way appeared on NO screen — not as
    | owed, not as paid, not as anything — so a notary whose client was abroad
    | watched a completed job earn nothing with no way to ask about it.
    */

    public function test_a_foreign_currency_job_is_surfaced_as_earned_but_unpayable(): void
    {
        $notary = $this->makeNotary(['commission_rate' => 50]);

        $this->makeCompletedJob(
            $notary,
            10000,                          // $100.00, in cents
            ['currency' => 'USD'],
            ['currency' => 'USD'],
        );

        $rows = app(PayoutService::class)->unpayableEarnings($notary);

        $this->assertSame(['USD'], $rows->keys()->all());
        $this->assertSame(1, $rows['USD']['count']);
        $this->assertSame(5000, $rows['USD']['shareMinor']);
    }

    public function test_naira_work_is_not_counted_as_unpayable(): void
    {
        $notary = $this->makeNotary();

        // The ordinary case: payable, and therefore already visible as owed.
        $this->makeCompletedJob($notary);

        $this->assertTrue(app(PayoutService::class)->unpayableEarnings($notary)->isEmpty());
    }

    public function test_unfinished_offsite_and_already_paid_dollar_work_is_excluded(): void
    {
        $notary = $this->makeNotary(['commission_rate' => 50]);
        $payouts = app(PayoutService::class);

        // Not finished: completion is what earns, here as everywhere else.
        $this->makeCompletedJob(
            $notary,
            10000,
            ['currency' => 'USD', 'status' => RequestStatus::Submitted->value, 'completed_at' => null],
            ['currency' => 'USD'],
        );

        // Offsite: the notary brought the work and paid us a fee for sealing
        // it, so there was never a share of it to send them.
        $this->makeCompletedJob(
            $notary,
            10000,
            ['currency' => 'USD', 'is_offsite' => true],
            ['currency' => 'USD'],
        );

        $this->assertTrue($payouts->unpayableEarnings($notary)->isEmpty());

        // Attached to a payout already — settled by hand and recorded, so it
        // must stop being listed as outstanding. The payout_id attachment is
        // the ledger, exactly as it is for naira work.
        $claimed = $this->makeCompletedJob(
            $notary,
            10000,
            ['currency' => 'USD'],
            ['currency' => 'USD'],
        );

        $this->assertSame(1, $payouts->unpayableEarnings($notary)['USD']['count']);

        $claimed->update(['payout_id' => Payout::create([
            'reference'    => 'NVN-PO-TEST-1',
            'notary_profile_id' => $notary->id,
            'amount'       => 5000,
            'currency'     => 'USD',
            'status'       => 'paid',
            'period_start' => now()->subMonth(),
            'period_end'   => now(),
        ])->id]);

        $this->assertTrue($payouts->unpayableEarnings($notary)->isEmpty());
    }

    public function test_the_payout_screen_says_a_foreign_job_cannot_be_sent_by_transfer(): void
    {
        $notary = $this->makeNotary(['commission_rate' => 50]);

        $this->makeCompletedJob(
            $notary,
            10000,
            ['currency' => 'USD'],
            ['currency' => 'USD'],
        );

        // The figure the admin screen prints, from the same method it calls.
        // Asserted through the service rather than by rendering the Filament
        // page, which needs a booted panel — the sentence is assembled from
        // this and nothing else.
        $rows = app(PayoutService::class)->unpayableEarnings($notary);

        $this->assertSame(
            '$50.00',
            NotarizationRequest::money($rows['USD']['shareMinor'], 'USD'),
        );
    }

    /**
     * Nothing is notarized for nothing because a column was left empty.
     *
     * Ordinary work freezes no unit price, so feeMinor() reads price_usd live
     * — and 0 there is a free notarization rather than a visible error. The
     * dollar prices in the live data look like placeholders (the naira figure
     * with the zeros dropped), which is exactly the state in which a new
     * category gets added and its dollar column forgotten.
     */
    public function test_a_dollar_request_cannot_pick_a_category_with_no_dollar_price(): void
    {
        $client = $this->makeClient();
        $notary = $this->makeNotary();
        $unpriced = $this->makeService($notary, 2500000, ['price_usd' => 0]);
        $priced = $this->makeService($notary, 2500000, ['price_usd' => 1600]);

        $request = NotarizationRequest::create([
            'reference' => 'NVN-CUR-1',
            'client_id' => $client->id,
            'status'    => RequestStatus::Draft->value,
            'currency'  => 'USD',
        ]);

        $this->actingAs($client)
            ->post(route('client.marketplace.select', ['request' => $request->id]), [
                'notary_id'  => $notary->id,
                'service_id' => $unpriced->id,
            ])
            ->assertSessionHasErrors('service_id');

        $this->assertNull($request->fresh()->service_id);

        // The priced one goes through, so the guard is about the empty column
        // and not about dollars.
        $this->actingAs($client)
            ->post(route('client.marketplace.select', ['request' => $request->id]), [
                'notary_id'  => $notary->id,
                'service_id' => $priced->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($priced->id, $request->fresh()->service_id);
    }
}
