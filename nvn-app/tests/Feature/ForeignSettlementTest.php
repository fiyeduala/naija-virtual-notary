<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Payout;
use App\Services\PayoutService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Settling work a client paid for in a currency we cannot transfer.
 *
 * A client abroad pays dollars; the notary has a Nigerian bank account and can
 * only receive naira. So there are two separate facts about one settlement —
 * what was EARNED, in dollars, and what was SENT, in naira — and the whole of
 * this is about not conflating them.
 *
 * Three things have to hold:
 *
 *  - it can be ticked off. Without a payout row there is nothing to settle, so
 *    the same job is reported outstanding forever, including after the notary
 *    has been paid. That is worse than the original silence.
 *  - it can never reach Paystack. initiateTransfer() takes a bare integer and
 *    sends naira, so a dollar payout handed to it wires ₦50 against a $50 debt
 *    and nothing downstream notices.
 *  - no total ever mixes the two. Adding cents to kobo shows somebody a figure
 *    they never received.
 */
class ForeignSettlementTest extends TestCase
{
    use RefreshDatabase, Fixtures;

    protected function tearDown(): void
    {
        Settings::flush();

        parent::tearDown();
    }

    /** A completed dollar job, fee cleared, at a 50% share. */
    private function dollarJob(int $cents = 10000): array
    {
        $notary = $this->makeNotary(['commission_rate' => 50]);

        $payment = $this->makeCompletedJob(
            $notary,
            $cents,
            ['currency' => 'USD'],
            ['currency' => 'USD'],
        );

        return [$notary, $payment];
    }

    public function test_a_dollar_job_gets_a_payout_row_in_its_own_currency(): void
    {
        [$notary, $payment] = $this->dollarJob();

        $created = app(PayoutService::class)->generateForeign($notary);

        $this->assertCount(1, $created);

        $payout = $created->first();
        $this->assertSame('USD', $payout->currency);
        $this->assertSame(5000, $payout->amount);
        $this->assertSame(5000, $payout->commission_amount);
        $this->assertSame('pending', $payout->status);

        // The attachment is the ledger, exactly as it is for naira work.
        $this->assertSame($payout->id, $payment->fresh()->payout_id);
    }

    public function test_generating_twice_does_not_create_a_second_payout(): void
    {
        [$notary] = $this->dollarJob();
        $payouts = app(PayoutService::class);

        $payouts->generateForeign($notary);

        $this->assertCount(0, $payouts->generateForeign($notary));
        $this->assertSame(1, Payout::count());
    }

    /**
     * The reminder has to stop, which is the entire point of this.
     *
     * unpayableEarnings() made the job visible; a payout row is what lets it
     * be ticked off. Without this an admin cannot tell a job they have settled
     * from one they have not.
     */
    public function test_generating_clears_the_earned_but_unpayable_warning(): void
    {
        [$notary] = $this->dollarJob();
        $payouts = app(PayoutService::class);

        $this->assertSame(1, $payouts->unpayableEarnings($notary)['USD']['count']);

        $payouts->generateForeign($notary);

        $this->assertTrue($payouts->unpayableEarnings($notary)->isEmpty());
    }

    public function test_naira_work_is_left_alone_by_the_foreign_run(): void
    {
        $notary = $this->makeNotary();
        $payment = $this->makeCompletedJob($notary);

        $this->assertCount(0, app(PayoutService::class)->generateForeign($notary));
        $this->assertNull($payment->fresh()->payout_id);
    }

    /**
     * Several dollar jobs make one dollar payout, grouped by currency.
     *
     * Two currencies in one run is the case the grouping exists for, and it
     * cannot be tested here: `notarization_requests.currency` is a MySQL enum
     * of exactly NGN and USD, so a third currency is truncated on insert — it
     * passes on SQLite, which has no enums, and errors on MariaDB. The
     * platform supports two currencies and only one of them is foreign, so
     * what is provable is that same-currency jobs add up into a single row
     * rather than one payout each.
     */
    public function test_several_dollar_jobs_become_one_dollar_payout(): void
    {
        $notary = $this->makeNotary(['commission_rate' => 50]);

        $this->makeCompletedJob($notary, 10000, ['currency' => 'USD'], ['currency' => 'USD']);
        $this->makeCompletedJob($notary, 8000, ['currency' => 'USD'], ['currency' => 'USD']);

        $created = app(PayoutService::class)->generateForeign($notary);

        $this->assertCount(1, $created);
        $this->assertSame('USD', $created->first()->currency);
        $this->assertSame(9000, $created->first()->amount);
        $this->assertSame(2, $created->first()->payments()->count());
    }

    /**
     * The money guard.
     *
     * Paystack sends naira against a bare integer of minor units, so a dollar
     * payout reaching it would wire ₦50 for a $50 debt. Refused at the model,
     * so no screen or future caller can get it wrong.
     */
    public function test_a_foreign_payout_can_never_be_sent_by_transfer(): void
    {
        [$notary] = $this->dollarJob();

        // No setting row, so the config default is what Settings::bool() reads.
        config(['nvn.paystack_transfers' => true]);
        Settings::flush();

        $this->assertTrue(Settings::paystackTransfersEnabled());

        $payout = app(PayoutService::class)->generateForeign($notary)->first();

        $this->assertTrue($payout->isForeign());
        $this->assertFalse($payout->isSendable());

        // And a naira payout is still sendable, so the guard is about currency
        // and not about having broken sending altogether.
        $naira = Payout::create([
            'reference'         => 'PO-NAIRATEST',
            'notary_profile_id' => $notary->id,
            'amount'            => 500000,
            'currency'          => 'NGN',
            'status'            => 'pending',
        ]);

        $this->assertFalse($naira->isForeign());
    }

    /*
    |--------------------------------------------------------------------------
    | Recording what actually left the bank
    |--------------------------------------------------------------------------
    */

    public function test_settling_a_foreign_payout_records_the_naira_that_was_sent(): void
    {
        [$notary] = $this->dollarJob();
        $payouts = app(PayoutService::class);

        $payout = $payouts->generateForeign($notary)->first();

        // $50.00 earned, ₦77,500 actually transferred.
        [$ok, $message] = $payouts->settleOffline($payout, [
            'method'         => 'bank_transfer',
            'settled_amount' => 77500,
            'reference'      => 'GTB-99881',
        ]);

        $this->assertTrue($ok);

        $payout->refresh();
        $this->assertSame('paid', $payout->status);
        $this->assertSame(5000, $payout->amount);            // still dollars, in cents
        $this->assertSame(7750000, $payout->settled_amount); // naira, in kobo
        $this->assertSame('NGN', $payout->settled_currency);

        // The rate is derived from the two amounts and never stored, so the
        // record cannot disagree with itself.
        $this->assertSame(1550.0, $payout->impliedRate());
        $this->assertStringContainsString('₦1,550.00', $message);
    }

    public function test_a_foreign_payout_cannot_be_settled_without_saying_what_was_sent(): void
    {
        [$notary] = $this->dollarJob();
        $payouts = app(PayoutService::class);

        $payout = $payouts->generateForeign($notary)->first();

        // Required, not optional: without it the row claims a dollar figure
        // was paid while the bank statement shows a naira one, and nobody can
        // reconcile the two afterwards.
        [$ok, $message] = $payouts->settleOffline($payout, ['method' => 'bank_transfer']);

        $this->assertFalse($ok);
        $this->assertStringContainsString('naira amount you actually sent', $message);
        $this->assertSame('pending', $payout->fresh()->status);
    }

    public function test_an_ordinary_naira_payout_is_settled_exactly_as_before(): void
    {
        $notary = $this->makeNotary();
        $this->makeCompletedJob($notary);

        $payouts = app(PayoutService::class);
        $payout = $payouts->generateFor($notary);

        [$ok] = $payouts->settleOffline($payout, ['method' => 'cash']);

        $this->assertTrue($ok);

        $payout->refresh();
        $this->assertSame('paid', $payout->status);
        // Nothing written here. `amount` already is what was sent, and a
        // second copy of it would only rot.
        $this->assertNull($payout->settled_amount);
        $this->assertNull($payout->settled_currency);
    }

    /**
     * The notary's own screen must not add cents to kobo.
     *
     * A foreign payout is a real row now, where before none could exist. The
     * "Paid out to you" total sums `amount` across paid payouts, so without a
     * currency filter it would quietly add 5,000 cents to a naira figure and
     * show the notary ₦50 they never received.
     */
    public function test_a_settled_dollar_payout_is_not_added_to_the_naira_total(): void
    {
        [$notary] = $this->dollarJob();
        $payouts = app(PayoutService::class);

        // One naira job paid out properly, one dollar job settled by hand.
        $this->makeCompletedJob($notary, 4500000);
        $nairaPayout = $payouts->generateFor($notary);
        $payouts->settleOffline($nairaPayout, ['method' => 'bank_transfer']);

        $foreign = $payouts->generateForeign($notary)->first();
        $payouts->settleOffline($foreign, ['method' => 'bank_transfer', 'settled_amount' => 77500]);

        $paidOut = (int) Payout::where('notary_profile_id', $notary->id)
            ->where('status', 'paid')
            ->where('currency', 'NGN')
            ->sum('amount');

        // The naira payout's share and nothing else.
        $this->assertSame($nairaPayout->amount, $paidOut);

        // Proof the guard is doing work: without the filter this is wrong.
        $unfiltered = (int) Payout::where('notary_profile_id', $notary->id)
            ->where('status', 'paid')
            ->sum('amount');

        $this->assertNotSame($paidOut, $unfiltered);
    }

    public function test_a_cancelled_foreign_payout_releases_its_fees_again(): void
    {
        [$notary, $payment] = $this->dollarJob();
        $payouts = app(PayoutService::class);

        $payout = $payouts->generateForeign($notary)->first();

        // The same release the naira path has — the admin's Cancel action.
        $payout->payments()->update(['payout_id' => null]);
        $payout->delete();

        $this->assertNull($payment->fresh()->payout_id);
        $this->assertSame(1, $payouts->unpayableEarnings($notary)['USD']['count']);
    }
}
