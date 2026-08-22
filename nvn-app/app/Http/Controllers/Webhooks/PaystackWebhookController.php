<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Notary\OnboardingFeeController;
use App\Services\OffsiteNotarizationService;
use App\Services\PayoutService;
use App\Services\PaystackService;
use App\Services\RequestFulfillmentService;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Paystack webhook — the authoritative source of payment truth.
 *
 * REPLACES the Phase 3 version: now routes by metadata.purpose to handle both
 * onboarding fees (Phase 3) and request fees (Phase 5). Keep the CSRF exclusion
 * for 'webhooks/paystack' in bootstrap/app.php.
 */
class PaystackWebhookController extends Controller
{
    public function __construct(
        private PaystackService $paystack,
        private RequestFulfillmentService $fulfillment,
        private PayoutService $payouts,
        private OffsiteNotarizationService $offsite,
    ) {}

    public function handle(Request $request): Response
    {
        $raw = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        if (! $this->paystack->isValidWebhook($raw, $signature)) {
            return response('Invalid signature', 401);
        }

        $event = $request->input('event');
        $data  = $request->input('data', []);
        $reference = $data['reference'] ?? null;
        $purpose = $data['metadata']['purpose'] ?? null;

        if ($event === 'charge.success' && $reference) {
            AuditLogger::record('paystack.charge_success', 'payment', null, [
                'reference' => $reference,
                'purpose'   => $purpose,
            ]);

            match ($purpose) {
                'onboarding_fee' => OnboardingFeeController::markPaid($reference),
                'request_fee'    => $this->fulfillment->markPaid($reference),
                // A notary paying us to seal their own offsite job. Settled on
                // its own path because nothing in the marketplace path applies:
                // no notary to notify, no response clock to start, and no share
                // of it owed back to anybody.
                'offsite_fee'    => $this->offsite->settleReference($reference),
                default          => null,
            };
        }

        // Money going OUT: notary payouts. Paystack is the only authority on
        // whether a transfer landed — a 200 from /transfer only means it was
        // accepted, so a payout stays "processing" until one of these arrives.
        if (str_starts_with((string) $event, 'transfer.')) {
            $this->handleTransfer((string) $event, $data);
        }

        return response('OK', 200);
    }

    /**
     * A transfer is identified by our own reference where possible, since we set
     * it before Paystack ever answers; transfer_code is the fallback.
     */
    private function handleTransfer(string $event, array $data): void
    {
        $key = $data['reference'] ?? $data['transfer_code'] ?? null;

        if (! $key) {
            return;
        }

        AuditLogger::record('paystack.' . str_replace('.', '_', $event), 'payout', null, [
            'reference'     => $data['reference'] ?? null,
            'transfer_code' => $data['transfer_code'] ?? null,
        ]);

        match ($event) {
            'transfer.success'  => $this->payouts->markPaid($key),
            // A reversal is a failure that happened later: the bank sent the
            // money back, so the notary is owed it again exactly as if the
            // transfer had been declined outright.
            'transfer.failed',
            'transfer.reversed' => $this->payouts->markFailed($key, $this->failureReason($event, $data)),
            default             => null,
        };
    }

    private function failureReason(string $event, array $data): string
    {
        $stated = $data['reason'] ?? $data['failures'] ?? $data['message'] ?? null;

        $prefix = $event === 'transfer.reversed'
            ? 'Reversed by the bank'
            : 'Declined';

        return is_string($stated) && $stated !== ''
            ? $prefix . ': ' . $stated
            : $prefix . '. Paystack gave no reason.';
    }
}
