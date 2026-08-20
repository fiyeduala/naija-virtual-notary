<?php

namespace App\Http\Controllers\Client;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ReportEventToMeta;
use App\Models\NotarizationRequest;
use App\Models\Payment;
use App\Services\PaystackService;
use App\Services\RequestFulfillmentService;
use App\Support\MetaAttribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RequestPaymentController extends Controller
{
    public function __construct(
        private PaystackService $paystack,
        private RequestFulfillmentService $fulfillment,
    ) {}

    /** Initialize the request-fee transaction and redirect to hosted checkout. */
    public function pay(NotarizationRequest $request): RedirectResponse
    {
        $this->authorizeOwner($request);

        // Must have a notary, service, and scheduled session before paying.
        $request->loadMissing('service', 'session');
        if (! $request->notary_id || ! $request->service_id || ! $request->session) {
            return redirect()->route('client.marketplace.index', $request)
                ->with('status', 'Choose a notary and a time slot first.');
        }

        // An unanswered category query means the price on this request is the
        // wrong one. Sending them to checkout now would take money against a
        // service the desk has already said does not apply.
        if ($request->hasOpenCategoryQuery()) {
            return redirect()->route('client.request.category.show', $request);
        }

        // What is actually owed, not what the job costs. On a first payment
        // the two are the same figure; they part company when a category query
        // has been answered with a dearer service, and then charging the fee
        // again would take the client's first payment twice.
        //
        // Every document on the request is counted, not just the primary one —
        // see NotarizationRequest::feeMinor(). The client agreed to this same
        // figure on the review screen, which reads it from the same place.
        $amount = $request->balanceMinor();

        // Guard against paying twice. Asked of the balance rather than the
        // status, because status alone cannot tell a paid request from one
        // that is paid and owes a difference — and it is the money question
        // either way.
        if ($amount <= 0) {
            return redirect()->route('client.dashboard')
                ->with('status', 'This request is already paid.');
        }

        $reference = $this->paystack->reference('req');

        // A second sighting of the same browser, taken at the last moment it is
        // in front of us. Meta matches partly on IP and user agent, so the
        // closer to the payment these are read the better the event matches —
        // and the click id itself is kept from whichever sighting saw it first.
        $attribution = MetaAttribution::merge(
            $request->attribution,
            MetaAttribution::capture(request()),
        );

        if ($attribution !== []) {
            $request->update(['attribution' => $attribution]);
        }

        $payment = Payment::create([
            'request_id'         => $request->id,
            'user_id'            => Auth::id(),
            'type'               => 'request_fee',
            'amount'             => $amount,
            'currency'           => $request->currency,
            'paystack_reference' => $reference,
            'status'             => 'pending',
            // On the payment as well as on the request, because a client can
            // start checkout on their phone and finish on a laptop; the row
            // that clears should carry the browser that opened it.
            'meta'               => $attribution !== [] ? ['fb' => $attribution] : null,
        ]);

        // Mark the request submitted (intake complete, awaiting payment).
        if ($request->status === RequestStatus::Draft) {
            $request->update(['status' => RequestStatus::Submitted, 'submitted_at' => now()]);
        }

        $init = $this->paystack->initializeTransaction(
            email: Auth::user()->email,
            amountMinor: $amount,
            reference: $reference,
            callbackUrl: route('client.request.payment.callback', $request),
            currency: $request->currency,
            metadata: ['purpose' => 'request_fee', 'request_id' => $request->id],
        );

        if (! $init['authorization_url']) {
            return back()->withErrors(['payment' => 'Could not start payment. Please try again.']);
        }

        // Reported as well as the purchase, and for a specific reason: Meta's
        // optimiser needs roughly fifty conversions an ad set a week before it
        // stops guessing, and a notary platform will take a long time to pay
        // out fifty notarizations in seven days. Reaching checkout happens far
        // more often, so it is the signal worth optimising towards; the
        // purchase remains the one to judge the spend on.
        ReportEventToMeta::dispatch($payment->id, 'InitiateCheckout');

        return redirect()->away($init['authorization_url']);
    }

    /** Paystack redirect-back. Verify for instant feedback; webhook is authoritative. */
    public function callback(NotarizationRequest $request): RedirectResponse
    {
        $this->authorizeOwner($request);

        $reference = request('reference') ?? request('trxref');
        if ($reference) {
            try {
                $data = $this->paystack->verifyTransaction($reference);
                if ($this->paystack->isSuccessful($data)) {
                    $this->fulfillment->markPaid($reference);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Paystack callback verification failed', [
                    'reference' => $reference,
                    'error'     => $e->getMessage(),
                ]);
                // Webhook will confirm shortly; fall through to status page.
            }
        }

        return redirect()->route('client.request.payment.status', $request);
    }

    public function status(NotarizationRequest $request): View
    {
        $this->authorizeOwner($request);
        $request->load('notary.user', 'service', 'session');

        return view('client.request.payment-status', ['request' => $request]);
    }

    private function authorizeOwner(NotarizationRequest $request): void
    {
        abort_unless($request->client_id === Auth::id(), 403);
    }
}
