<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Thin wrapper around the Paystack API.
 *
 * Used for hosted-checkout flows (onboarding fee in this phase, client request
 * fees in a later phase) and, eventually, Transfers for notary payouts.
 *
 * Flow for hosted checkout:
 *   1. initializeTransaction() -> returns an authorization_url
 *   2. redirect the user there; they pay on Paystack's secure page
 *   3. Paystack redirects back to our callback URL
 *   4. a webhook ALSO hits us independently -> the source of truth
 *   5. verifyTransaction() confirms status before we act
 */
class PaystackService
{
    private string $secret;
    private string $base;

    public function __construct()
    {
        $this->secret = (string) config('paystack.secret_key');
        $this->base   = rtrim((string) config('paystack.base_url'), '/');
    }

    private function client()
    {
        $caBundle = storage_path('cacert.pem');

        return Http::withToken($this->secret)
            ->acceptJson()
            ->timeout(20)
            ->withOptions(file_exists($caBundle) ? ['verify' => $caBundle] : []);
    }

    /** Generate a unique reference for a transaction. */
    public function reference(string $prefix = 'nvn'): string
    {
        return $prefix . '_' . Str::lower(Str::random(16));
    }

    /**
     * Initialize a transaction. Amount is in MINOR units (kobo for NGN, cents for USD).
     * Returns ['authorization_url' => ..., 'reference' => ...].
     */
    public function initializeTransaction(
        string $email,
        int $amountMinor,
        string $reference,
        string $callbackUrl,
        string $currency = 'NGN',
        array $metadata = [],
    ): array {
        $response = $this->client()->post($this->base . '/transaction/initialize', [
            'email'        => $email,
            'amount'       => $amountMinor,
            'reference'    => $reference,
            'callback_url' => $callbackUrl,
            'currency'     => $currency,
            'metadata'     => $metadata,
        ]);

        $response->throw();
        $data = $response->json('data');

        return [
            'authorization_url' => $data['authorization_url'] ?? null,
            'reference'         => $data['reference'] ?? $reference,
        ];
    }

    /** Verify a transaction by reference. Returns the data array (status etc.). */
    public function verifyTransaction(string $reference): array
    {
        $response = $this->client()->get($this->base . '/transaction/verify/' . $reference);
        $response->throw();

        return $response->json('data') ?? [];
    }

    /** True if the verified transaction is a success. */
    public function isSuccessful(array $verifyData): bool
    {
        return ($verifyData['status'] ?? null) === 'success';
    }

    /**
     * Validate an incoming webhook against the x-paystack-signature header.
     * Paystack signs the raw request body with HMAC-SHA512 using your secret key.
     */
    public function isValidWebhook(string $rawBody, ?string $signature): bool
    {
        if (! $signature) {
            return false;
        }

        $expected = hash_hmac('sha512', $rawBody, $this->secret);

        return hash_equals($expected, $signature);
    }

    /**
     * The list of banks Paystack can pay to, as [code => name].
     *
     * `perPage` is deliberately large: the Nigerian list runs to several hundred
     * institutions once microfinance banks are included, and a truncated list
     * silently hides a notary's bank from the dropdown.
     */
    public function banks(string $currency = 'NGN'): array
    {
        $response = $this->client()->get($this->base . '/bank', [
            'currency' => $currency,
            'perPage'  => 500,
        ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('data') ?? [])
            ->filter(fn ($bank) => ! empty($bank['code']) && ! empty($bank['name']))
            ->mapWithKeys(fn ($bank) => [$bank['code'] => $bank['name']])
            ->sort()
            ->all();
    }

    /** Resolve a bank account name (for verifying notary bank details). */
    public function resolveAccount(string $accountNumber, string $bankCode): ?array
    {
        $response = $this->client()->get($this->base . '/bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code'      => $bankCode,
        ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('data');
    }

    /**
     * Send money from the Paystack balance to a recipient.
     *
     * Returns the raw data array. The `status` inside it is the one that
     * matters, and it has three shapes:
     *   'success' — done, money moved
     *   'pending' — accepted, the transfer webhook will confirm
     *   'otp'     — an OTP was sent to the account owner; call finalizeTransfer
     *
     * Which of those you get depends on whether transfer OTP is switched on in
     * the Paystack dashboard, not on anything in this code.
     *
     * Transfers debit your Paystack BALANCE, so the balance must be funded —
     * a settlement schedule that sweeps everything to your bank each day will
     * leave nothing to pay out with.
     */
    public function initiateTransfer(
        int $amountMinor,
        string $recipientCode,
        string $reference,
        string $reason,
        string $currency = 'NGN',
    ): array {
        $response = $this->client()->post($this->base . '/transfer', [
            'source'    => 'balance',
            'amount'    => $amountMinor,
            'recipient' => $recipientCode,
            'reference' => $reference,
            'reason'    => $reason,
            'currency'  => $currency,
        ]);

        // A rejected transfer carries the reason in the body, which is worth far
        // more than a status code ("insufficient balance", "transfers not
        // enabled on this account"), so read it rather than throwing.
        return [
            'ok'      => $response->successful(),
            'data'    => $response->json('data') ?? [],
            'message' => $response->json('message') ?? 'Paystack did not accept the transfer.',
        ];
    }

    /** Complete a transfer that Paystack held for OTP confirmation. */
    public function finalizeTransfer(string $transferCode, string $otp): array
    {
        $response = $this->client()->post($this->base . '/transfer/finalize_transfer', [
            'transfer_code' => $transferCode,
            'otp'           => $otp,
        ]);

        return [
            'ok'      => $response->successful(),
            'data'    => $response->json('data') ?? [],
            'message' => $response->json('message') ?? 'The OTP was not accepted.',
        ];
    }

    /** Current state of a transfer, for reconciling a missed webhook. */
    public function fetchTransfer(string $transferCodeOrId): ?array
    {
        $response = $this->client()->get($this->base . '/transfer/' . $transferCodeOrId);

        return $response->successful() ? $response->json('data') : null;
    }

    /** Create a transfer recipient (used later for payouts). Returns recipient_code. */
    public function createTransferRecipient(string $name, string $accountNumber, string $bankCode, string $currency = 'NGN'): ?string
    {
        $response = $this->client()->post($this->base . '/transferrecipient', [
            'type'           => 'nuban',
            'name'           => $name,
            'account_number' => $accountNumber,
            'bank_code'      => $bankCode,
            'currency'       => $currency,
        ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('data.recipient_code');
    }
}
