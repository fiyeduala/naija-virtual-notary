<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\MetaConversionsService;
use App\Support\MetaAttribution;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Tells Meta that a payment happened.
 *
 * Queued rather than inline, for two reasons. Paystack gives its webhook a few
 * seconds before it decides the endpoint is down and starts retrying, and an
 * HTTP call to Meta inside that window is a good way to be settled twice. And
 * the Conversions API accepts an event up to seven days after the fact, so
 * there is no hurry at all — even the five-minute queue cron on a shared host
 * is early.
 *
 * afterCommit, so a settlement that rolls back reports nothing. A conversion
 * Meta has been told about cannot be taken back.
 *
 * The event id is derived from the payment id, which makes the whole thing
 * idempotent from Meta's side as well as ours: a retried job, a webhook and a
 * callback arriving together, or the browser sending its own copy of the same
 * purchase all collapse into one conversion.
 */
class ReportEventToMeta implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Meta is rarely down for long, and there is a week to get this through. */
    public array $backoff = [60, 600];

    public function __construct(
        public int $paymentId,
        public string $event = 'Purchase',
    ) {
        // Set here rather than as a property, because Queueable already declares
        // $afterCommit and redeclaring it is a fatal composition error. Every
        // dispatch of this job waits for the transaction: a settlement that
        // rolls back must report nothing, and a conversion Meta has been told
        // about cannot be taken back.
        $this->afterCommit();
    }

    public function handle(MetaConversionsService $meta): void
    {
        if (! $meta->configured()) {
            return; // reporting is opt-in; nothing is configured, nothing is sent
        }

        $payment = Payment::with(['request.client', 'request.service', 'user'])->find($this->paymentId);

        if (! $payment) {
            return;
        }

        // A Purchase is a claim that money arrived. The dispatch site already
        // checks this, but the job can run minutes later on a different process,
        // and reporting revenue that was since reversed is the one mistake here
        // with a cost attached.
        if ($this->event === 'Purchase' && $payment->status !== 'successful') {
            Log::info("[meta] purchase not reported: payment {$payment->id} is {$payment->status}");

            return;
        }

        $request = $payment->request;
        $attribution = $this->attribution($payment);

        // The rule for money that did not come through checkout: a bank
        // transfer counts as a conversion only for a client who arrived on an
        // ad. Without a click id there is nothing tying the two together, and
        // reporting it anyway would credit the campaign with walk-in business
        // and quietly corrupt the only number the ad is judged on.
        if ($payment->isOffline() && empty($attribution['fbc'])) {
            Log::info("[meta] offline payment {$payment->id} not reported: no ad click recorded for this request");

            return;
        }

        $value = $meta->majorUnits((int) $payment->amount);

        if (! $meta->withinCeiling($value)) {
            // Either the fee really is extraordinary or somebody has sent kobo
            // where naira belonged. Both are worth a human looking, and neither
            // is worth teaching the optimiser.
            Log::error("[meta] refusing to report {$value} {$payment->currency} for payment {$payment->id}"
                . ' — above the sanity ceiling; check that the amount is in naira, not kobo');

            return;
        }

        $meta->send(
            event: $this->event,
            eventId: $this->eventId($payment),
            attribution: $attribution,
            user: $payment->user ?? $request?->client,
            custom: array_filter([
                'value'        => $value,
                'currency'     => $payment->currency,
                'order_id'     => $payment->paystack_reference,
                'content_type' => 'product',
                'content_ids'  => $request ? [$request->reference] : null,
                'content_name' => $request?->service?->service_type ?? 'Notarization',
                'num_items'    => $request?->billableDocumentCount(),
            ], fn ($v) => $v !== null),
            eventTime: $this->eventTime($payment),
        );
    }

    public function failed(\Throwable $e): void
    {
        Log::warning("[meta] gave up reporting {$this->event} for payment {$this->paymentId}: " . $e->getMessage());
    }

    /**
     * What we know about the click that led here.
     *
     * The payment's own copy is preferred because it was taken at checkout and
     * so carries the browser Meta saw most recently. The request's copy is the
     * fallback and the more important half: it was written when the client
     * first uploaded their document, which is the only sighting that exists at
     * all for someone who never opened Paystack and paid by transfer instead.
     */
    private function attribution(Payment $payment): array
    {
        $onRequest = $payment->request?->attribution ?? [];
        $onPayment = ($payment->meta ?? [])['fb'] ?? [];

        return MetaAttribution::merge(
            is_array($onRequest) ? $onRequest : [],
            is_array($onPayment) ? $onPayment : [],
        );
    }

    /** Stable across retries and shared with the browser, so Meta counts once. */
    private function eventId(Payment $payment): string
    {
        return 'nvn-' . strtolower($this->event) . '-' . $payment->id;
    }

    /**
     * When it happened, as far as Meta will accept it.
     *
     * The API refuses anything older than seven days, and an admin recording a
     * transfer that arrived a fortnight ago is an ordinary Tuesday here. The
     * conversion is real either way, so it is reported with today's timestamp
     * rather than dropped — and the shift is written down, because a purchase
     * dated later than it happened is worth being able to explain.
     */
    private function eventTime(Payment $payment): int
    {
        $when = $payment->completed_at?->timestamp ?? time();
        $earliest = time() - (6 * 24 * 60 * 60);

        if ($when < $earliest) {
            Log::info("[meta] payment {$payment->id} cleared more than a week ago;"
                . ' reporting it with the current time, which is the oldest Meta accepts');

            return time();
        }

        return $when;
    }
}
