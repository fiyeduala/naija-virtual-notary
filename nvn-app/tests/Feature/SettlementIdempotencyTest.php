<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Jobs\ReportEventToMeta;
use App\Notifications\Admin\RequestPaidNotification;
use App\Notifications\NotaryNewRequestNotification;
use App\Services\RequestFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * A payment is confirmed once, however many times Paystack says so.
 *
 * Paystack reports the same payment at least twice — the browser callback and
 * the webhook — and retries the webhook when it is slow. Every one of those
 * arrives at markPaid(). If the second arrival did anything, a client would be
 * charged once and counted twice: two sales reported to Meta, two "new request"
 * emails to the notary, and a response clock restarted on a job already moving.
 */
class SettlementIdempotencyTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    private RequestFulfillmentService $fulfilment;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Bus::fake([ReportEventToMeta::class]);

        $this->fulfilment = app(RequestFulfillmentService::class);
    }

    public function test_a_payment_clears_and_the_request_becomes_paid(): void
    {
        $notary = $this->makeNotary();
        $payment = $this->makeUnpaidJob($notary);

        $this->fulfilment->markPaid($payment->paystack_reference);

        $payment->refresh();
        $request = $payment->request;

        $this->assertSame('successful', $payment->status);
        $this->assertNotNull($payment->completed_at);
        $this->assertSame(RequestStatus::Paid, $request->status);
        $this->assertNotNull($request->paid_at);
        $this->assertNotNull($request->fallback_due_at, 'the response clock starts when payment clears');
        Notification::assertSentToTimes($notary->user, NotaryNewRequestNotification::class, 1);
    }

    /** The whole point of the file: the second confirmation is a no-op. */
    public function test_confirming_the_same_payment_twice_changes_nothing_the_second_time(): void
    {
        $this->makeClient(['role' => 'admin']);
        $notary = $this->makeNotary();
        $payment = $this->makeUnpaidJob($notary);

        $this->fulfilment->markPaid($payment->paystack_reference);
        $first = $payment->fresh();
        $firstRequest = $first->request;

        $this->travel(5)->minutes();

        $this->fulfilment->markPaid($payment->paystack_reference);
        $second = $payment->fresh();
        $secondRequest = $second->request;

        $this->assertEquals($first->completed_at, $second->completed_at, 'the payment keeps the moment it first cleared');
        $this->assertEquals($firstRequest->paid_at, $secondRequest->paid_at);
        $this->assertEquals($firstRequest->fallback_due_at, $secondRequest->fallback_due_at, 'the clock is not restarted');

        Bus::assertDispatchedTimes(ReportEventToMeta::class, 1);
        Notification::assertSentToTimes($notary->user, NotaryNewRequestNotification::class, 1);
        Notification::assertSentTimes(RequestPaidNotification::class, 1);
    }

    /**
     * The notary hears about a job only once the money is in. A notary told
     * about unpaid work turns up for it.
     */
    public function test_the_notary_is_not_told_about_unpaid_work(): void
    {
        $notary = $this->makeNotary();
        $this->makeUnpaidJob($notary);

        Notification::assertNothingSentTo($notary->user);
    }

    /**
     * A payment arriving late for a job already in progress still counts as
     * money, but must not drag the job back to "paid" and restart its clock.
     */
    public function test_a_late_payment_does_not_reset_a_job_already_under_way(): void
    {
        $notary = $this->makeNotary();
        $payment = $this->makeCompletedJob(
            $notary,
            4500000,
            ['status' => RequestStatus::Accepted->value, 'completed_at' => null],
            ['status' => 'pending', 'completed_at' => null],
        );

        $this->fulfilment->markPaid($payment->paystack_reference);

        $this->assertSame('successful', $payment->fresh()->status, 'the money still counts');
        $this->assertSame(RequestStatus::Accepted, $payment->request->fresh()->status, 'but the job stays where it was');
        Notification::assertNothingSentTo($notary->user);
    }

    /** A reference nobody issued is ignored, not an error. */
    public function test_an_unknown_reference_does_nothing(): void
    {
        $this->fulfilment->markPaid('PS-NOT-OURS');

        Bus::assertNotDispatched(ReportEventToMeta::class);
        Notification::assertNothingSent();
    }
}
