<?php

namespace App\Http\Controllers\Client;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\NotarizationRequest;
use App\Models\Payment;
use App\Services\PaystackService;
use App\Services\RequestFulfillmentService;
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

        // Guard against paying twice.
        if (in_array($request->status, [RequestStatus::Paid, RequestStatus::Accepted], true)) {
            return redirect()->route('client.dashboard')
                ->with('status', 'This request is already paid.');
        }

        $amount = $request->service->priceFor($request->currency);
        $reference = $this->paystack->reference('req');

        Payment::create([
            'request_id'         => $request->id,
            'user_id'            => Auth::id(),
            'type'               => 'request_fee',
            'amount'             => $amount,
            'currency'           => $request->currency,
            'paystack_reference' => $reference,
            'status'             => 'pending',
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
