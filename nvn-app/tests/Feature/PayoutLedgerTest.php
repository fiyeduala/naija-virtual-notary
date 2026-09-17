<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Payment;
use App\Models\Payout;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * What a notary is owed, and that it is owed exactly once.
 *
 * payments.payout_id is the whole ledger: a cleared fee counts towards a
 * balance for as long as it is unattached, and attaching it is what makes it
 * paid. Every test here is really the same question asked from a different
 * side — can a fee be counted twice, or lost.
 *
 * Nothing in this file would announce itself if it broke. A double-count shows
 * up as a payout figure that looks a bit high, and a lost fee shows up as a
 * notary saying they were underpaid, months later, with nothing to check it
 * against. That is the argument for testing it rather than watching for it.
 */
class PayoutLedgerTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    private PayoutService $payouts;

    /** ₦45,000 in kobo — an ordinary notarization fee. */
    private const FEE = 4500000;

    protected function setUp(): void
    {
        parent::setUp();

        // Generating a payout writes an audit entry and can notify; neither is
        // what this file is about, and a real notification would try to send.
        Notification::fake();

        $this->payouts = app(PayoutService::class);
    }

    public function test_a_notary_is_owed_their_share_of_a_completed_job(): void
    {
        $notary = $this->makeNotary(['commission_rate' => 50]);
        $this->makeCompletedJob($notary, self::FEE);

        $this->assertSame(2250000, $this->payouts->owed($notary), '50% of ₦45,000 is ₦22,500');
    }

    public function test_the_commission_rate_is_respected_per_notary(): void
    {
        $keepsMore = $this->makeNotary(['commission_rate' => 30]);
        $this->makeCompletedJob($keepsMore, self::FEE);

        $this->assertSame(3150000, $this->payouts->owed($keepsMore), 'a 30% commission leaves the notary 70%');
    }

    public function test_generating_a_payout_claims_the_fees_and_clears_the_balance(): void
    {
        $notary = $this->makeNotary();
        $payment = $this->makeCompletedJob($notary, self::FEE);

        $payout = $this->payouts->generateFor($notary);

        $this->assertNotNull($payout);
        $this->assertSame(2250000, (int) $payout->amount, 'the payout pays the notary their share');
        $this->assertSame(2250000, (int) $payout->commission_amount, 'and records the platform half beside it');
        $this->assertSame($payout->id, $payment->fresh()->payout_id, 'the fee is attached to the payout');
        $this->assertSame(0, $this->payouts->owed($notary), 'and so is no longer owed');
    }

    /**
     * The failure this whole design exists to prevent. Two payout runs, one
     * job: the second must find nothing left to pay.
     */
    public function test_a_second_payout_run_cannot_pay_the_same_job_again(): void
    {
        $notary = $this->makeNotary();
        $this->makeCompletedJob($notary, self::FEE);

        $first = $this->payouts->generateFor($notary);
        $second = $this->payouts->generateFor($notary);

        $this->assertNotNull($first);
        $this->assertNull($second, 'there is nothing left to pay, so there is no second payout');
        $this->assertSame(1, Payout::where('notary_profile_id', $notary->id)->count());
    }

    /**
     * A failed transfer must put the money back. Without the release the fees
     * stay attached to a payout that never paid, and the notary's balance has
     * silently been erased — they are simply never paid for that work again.
     */
    public function test_a_failed_transfer_returns_the_fees_to_the_owed_pile(): void
    {
        $notary = $this->makeNotary();
        $this->makeCompletedJob($notary, self::FEE);

        $before = $this->payouts->owed($notary);
        $payout = $this->payouts->generateFor($notary);

        $this->assertSame(0, $this->payouts->owed($notary));

        $this->payouts->markFailed($payout->reference, 'Insufficient balance');

        $this->assertSame($before, $this->payouts->owed($notary), 'exactly what was owed before, to the kobo');
        $this->assertSame('failed', $payout->fresh()->status);
        $this->assertNull(Payment::where('payout_id', $payout->id)->first(), 'no fee is still attached to it');
    }

    /** And having been released, it can then actually be paid. */
    public function test_released_fees_can_be_paid_by_a_later_run(): void
    {
        $notary = $this->makeNotary();
        $this->makeCompletedJob($notary, self::FEE);

        $failed = $this->payouts->generateFor($notary);
        $this->payouts->markFailed($failed->reference, 'Insufficient balance');

        $retry = $this->payouts->generateFor($notary);

        $this->assertNotNull($retry, 'the work is owed again, so a new payout covers it');
        $this->assertSame(2250000, (int) $retry->amount);
    }

    /**
     * An offsite fee is money the notary paid the platform to seal their own
     * document. Paying them a share of it back would be the platform refunding
     * its own revenue.
     */
    public function test_an_offsite_fee_is_never_owed_to_the_notary(): void
    {
        $notary = $this->makeNotary();
        $this->makeCompletedJob($notary, 100000, ['is_offsite' => true]);

        $this->assertSame(0, $this->payouts->owed($notary));
        $this->assertNull($this->payouts->generateFor($notary));
    }

    /**
     * Paystack transfers reach Nigerian accounts in naira only, so a dollar fee
     * cannot be paid out this way and must not inflate a naira transfer.
     */
    public function test_a_dollar_fee_does_not_inflate_a_naira_payout(): void
    {
        $notary = $this->makeNotary();
        $this->makeCompletedJob($notary, self::FEE);
        $this->makeCompletedJob($notary, 9900, ['currency' => 'USD'], ['currency' => 'USD']);

        $this->assertSame(2250000, $this->payouts->owed($notary), 'only the naira job counts');
    }

    /** Money that has not cleared is not money. */
    public function test_an_unpaid_fee_is_not_owed(): void
    {
        $notary = $this->makeNotary();
        $this->makeUnpaidJob($notary, self::FEE);

        $this->assertSame(0, $this->payouts->owed($notary));
    }

    /** Nor is a job that has not been finished yet. */
    public function test_an_unfinished_job_is_not_owed(): void
    {
        $notary = $this->makeNotary();
        $this->makeCompletedJob($notary, self::FEE, ['status' => RequestStatus::Notarizing->value]);

        $this->assertSame(0, $this->payouts->owed($notary));
    }

    public function test_several_jobs_add_up(): void
    {
        $notary = $this->makeNotary();
        $this->makeCompletedJob($notary, self::FEE);
        $this->makeCompletedJob($notary, 2000000);
        $this->makeCompletedJob($notary, 1500000);

        $this->assertSame(4000000, $this->payouts->owed($notary), 'half of ₦45,000 + ₦20,000 + ₦15,000');

        $payout = $this->payouts->generateFor($notary);

        $this->assertSame(4000000, (int) $payout->amount);
        $this->assertSame(3, Payment::where('payout_id', $payout->id)->count());
    }

    /** One notary's work is never another's money. */
    public function test_a_payout_covers_only_its_own_notary(): void
    {
        $mine = $this->makeNotary();
        $theirs = $this->makeNotary();

        $this->makeCompletedJob($mine, self::FEE);
        $this->makeCompletedJob($theirs, self::FEE);

        $payout = $this->payouts->generateFor($mine);

        $this->assertSame(2250000, (int) $payout->amount);
        $this->assertSame(2250000, $this->payouts->owed($theirs), 'the other notary is untouched');
    }

    /**
     * The platform does not transfer money to itself. Its share of a job it
     * covered is commission it already holds.
     */
    public function test_the_platforms_own_notary_is_not_paid_out(): void
    {
        $platform = $this->makeNotary(['is_system_native' => true]);
        $this->makeCompletedJob($platform, self::FEE);

        $generated = $this->payouts->generateAll();

        $this->assertCount(0, $generated);
    }
}
