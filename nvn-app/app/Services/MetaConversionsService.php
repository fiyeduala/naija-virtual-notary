<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reports events to Meta's Conversions API.
 *
 * Server to server, because the event that matters — money clearing — happens
 * where no browser is watching: Paystack's webhook arrives from Paystack, and a
 * bank transfer is recorded by an admin days later. A browser pixel cannot see
 * either, so an ad optimised on the pixel alone is optimised on people reaching
 * the checkout page, not on people paying.
 *
 * Two rules the whole design turns on:
 *
 *  - Amounts are naira. payments.amount is kobo. A missing division reports a
 *    ₦45,000 sale as ₦4,500,000, and Meta answers by spending the budget
 *    hunting for more of the same imaginary customer. majorUnits() is the only
 *    place that division is allowed to happen, and withinCeiling() refuses
 *    anything absurd rather than trusting it.
 *
 *  - Personal details are hashed before they leave: SHA-256 over a normalised
 *    lower-cased value, which is what Meta's matching expects and also means
 *    the plaintext never crosses the wire.
 *
 * Nothing here throws. A conversion that cannot be reported is a marketing
 * problem, and it must never become a failed payment — the caller is a queued
 * job whose failure would otherwise retry the settlement path around it.
 */
class MetaConversionsService
{
    public function configured(): bool
    {
        return $this->datasetId() !== '' && $this->token() !== '';
    }

    /**
     * Send one event.
     *
     * $attribution is what MetaAttribution::capture() wrote down: fbc, fbp, ip,
     * user agent, url. $custom carries value, currency and content ids.
     *
     * $eventId is the deduplication key. Send the same id from the browser and
     * from here and Meta counts one conversion, not two — which is what makes
     * it safe to have both.
     */
    public function send(
        string $event,
        string $eventId,
        array $attribution = [],
        ?User $user = null,
        array $custom = [],
        ?int $eventTime = null,
    ): bool {
        if (! $this->configured()) {
            return false;
        }

        $userData = $this->userData($attribution, $user);

        // Meta needs at least one way to recognise the person. With no click id,
        // no browser id and no contact details there is nothing to match on, and
        // the event would be accepted and then quietly discarded.
        if ($userData === []) {
            Log::info("[meta] {$event} not sent: nothing to match the person on");

            return false;
        }

        $payload = array_filter([
            'event_name'       => $event,
            'event_time'       => $eventTime ?? time(),
            'event_id'         => $eventId,
            'action_source'    => 'website',
            'event_source_url' => $attribution['url'] ?? config('app.url'),
            'user_data'        => $userData,
            'custom_data'      => $custom !== [] ? $custom : null,
        ], fn ($v) => $v !== null);

        $body = ['data' => [$payload]];

        if (($test = $this->testEventCode()) !== '') {
            $body['test_event_code'] = $test;
        }

        $url = 'https://graph.facebook.com/' . $this->version()
             . '/' . $this->datasetId() . '/events';

        try {
            $response = Http::asJson()
                ->timeout(15)
                ->withQueryParameters(['access_token' => $this->token()])
                ->post($url, $body);
        } catch (ConnectionException $e) {
            Log::warning("[meta] could not reach the Conversions API for {$event}: " . $e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            // Meta puts the useful sentence in error.message; the status alone
            // says almost nothing, and the two failures worth telling apart —
            // an expired token and a retired API version — both arrive as 400.
            $message = $response->json('error.message') ?? Str::limit($response->body(), 300);

            Log::warning("[meta] {$event} rejected (HTTP {$response->status()}): {$message}");

            return false;
        }

        $received = (int) ($response->json('events_received') ?? 0);

        Log::info("[meta] {$event} reported, events_received={$received}, event_id={$eventId}");

        return $received > 0;
    }

    /**
     * Kobo to naira.
     *
     * The one place in the application allowed to do this division for Meta.
     * Everything Meta is told about money passes through here.
     */
    public function majorUnits(int $minor): float
    {
        return round($minor / 100, 2);
    }

    /**
     * Is this figure believable as a notarization fee?
     *
     * A guard against the kobo bug surviving a refactor. Real fees are tens of
     * thousands of naira; anything past the ceiling is a hundredfold error, and
     * reporting it once teaches the optimiser something expensive to unlearn.
     *
     * The ceiling is stated in naira, and a dollar fee is a smaller number for
     * the same money, so one figure is safe for both currencies.
     */
    public function withinCeiling(float $major): bool
    {
        $ceiling = (int) config('nvn.meta.max_value_ngn', 2000000);

        return $major > 0 && $major <= $ceiling;
    }

    /**
     * Everything Meta can use to recognise the person.
     *
     * fbc is the strongest signal by far — it is the click itself — and the
     * hashed contact details raise the match rate for someone whose cookies
     * were cleared between the click and the payment.
     */
    private function userData(array $attribution, ?User $user): array
    {
        $data = array_filter([
            'fbc'               => $attribution['fbc'] ?? null,
            'fbp'               => $attribution['fbp'] ?? null,
            'client_ip_address' => $attribution['ip'] ?? null,
            'client_user_agent' => $attribution['user_agent'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (! $user) {
            return $data;
        }

        [$first, $last] = $this->splitName((string) $user->full_name);

        return array_filter($data + [
            'em'          => $this->hash($user->email),
            'ph'          => $this->hashPhone($user->phone),
            'fn'          => $this->hash($first),
            'ln'          => $this->hash($last),
            // Meta's own docs allow an unhashed external id, but a raw primary
            // key is still a stable handle on a person; hashing it costs
            // nothing and matches just as well, since only we ever send it.
            'external_id' => hash('sha256', 'nvn-user-' . $user->id),
        ], fn ($v) => $v !== null);
    }

    /** Normalise then hash, per Meta's matching rules. Nothing stays nothing. */
    private function hash(?string $value): ?string
    {
        $value = trim(mb_strtolower((string) $value));

        return $value === '' ? null : hash('sha256', $value);
    }

    /**
     * Phone numbers, in the only form Meta matches: digits and country code,
     * no plus sign. A Nigerian number written 0803… is the same number as
     * +234803…, and sent as the former it matches nobody.
     */
    private function hashPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '234' . ltrim($digits, '0');
        }

        // Ten digits and no country code is a local Nigerian number written
        // without its leading zero.
        if (strlen($digits) === 10) {
            $digits = '234' . $digits;
        }

        return hash('sha256', $digits);
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return ['', ''];
        }

        $first = array_shift($parts);

        return [$first, implode(' ', $parts)];
    }

    private function datasetId(): string
    {
        return trim((string) config('nvn.meta.dataset_id', ''));
    }

    private function token(): string
    {
        return trim((string) config('nvn.meta.access_token', ''));
    }

    private function testEventCode(): string
    {
        return trim((string) config('nvn.meta.test_event_code', ''));
    }

    private function version(): string
    {
        return trim((string) config('nvn.meta.version', 'v23.0'), '/ ');
    }
}
