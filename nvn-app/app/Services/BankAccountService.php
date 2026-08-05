<?php

namespace App\Services;

use App\Models\NotaryBankDetail;
use App\Models\NotaryProfile;
use App\Support\AuditLogger;
use App\Support\Banks;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Saving and verifying a notary's payout account.
 *
 * Two things happen here that did not before:
 *
 *  1. The bank is stored as a CODE. Paystack addresses an account by NUBAN bank
 *     code; the free-text bank name the form used to collect cannot be paid to.
 *
 *  2. The account is resolved against the bank before it is trusted. Paystack
 *     returns the real name on the account, which is compared to the notary's.
 *     A mismatch does not block the save — a notary may legitimately be paid
 *     into a chambers or company account — but it is recorded so an admin can
 *     look before any money moves.
 *
 * Verification is best-effort by design. No outbound network on a locked-down
 * host, an API outage, or a dev machine with no keys must not stop a notary
 * completing their profile; the account simply stays unverified and no transfer
 * recipient is created for it.
 */
class BankAccountService
{
    public function __construct(private PaystackService $paystack) {}

    /**
     * Persist the account, verifying it where possible.
     *
     * $data: bank_code, account_number, account_name.
     */
    public function save(NotaryProfile $profile, array $data): NotaryBankDetail
    {
        $bankCode      = (string) $data['bank_code'];
        $accountNumber = preg_replace('/\D/', '', (string) $data['account_number']);

        $attributes = [
            'bank_code'   => $bankCode,
            'bank_name'   => Banks::name($bankCode) ?? 'Unknown bank',
            'account_number' => $accountNumber,
            'account_name'   => trim((string) $data['account_name']),
        ];

        $verification = $this->verify($profile, $accountNumber, $bankCode);

        $detail = $profile->bankDetails()->updateOrCreate(
            ['notary_profile_id' => $profile->id],
            $attributes + $verification,
        );

        AuditLogger::record('notary.bank_saved', 'notary_profile', $profile->id, [
            'bank_code'    => $bankCode,
            'verified'     => $verification['verified_at'] !== null,
            'name_matches' => $verification['name_matches'],
            // Never the full number, even in our own logs.
            'account_last4' => Str::substr($accountNumber, -4),
        ]);

        return $detail;
    }

    /**
     * Ask the bank who owns the account, and decide whether that looks like the
     * notary. Returns the columns to write; all null when we could not check.
     */
    public function verify(NotaryProfile $profile, string $accountNumber, string $bankCode): array
    {
        $blank = [
            'resolved_account_name'   => null,
            'verified_at'             => null,
            'name_matches'            => null,
            'paystack_recipient_code' => null,
        ];

        try {
            $resolved = $this->paystack->resolveAccount($accountNumber, $bankCode);
        } catch (\Throwable $e) {
            Log::warning('Bank account could not be verified with Paystack.', [
                'notary_profile_id' => $profile->id,
                'error'             => $e->getMessage(),
            ]);

            return $blank;
        }

        $resolvedName = $resolved['account_name'] ?? null;

        if (! $resolvedName) {
            return $blank;
        }

        return [
            'resolved_account_name'   => $resolvedName,
            'verified_at'             => now(),
            'name_matches'            => $this->looksLike($resolvedName, $profile),
            // The name on the recipient is the bank's, not the typed one, so a
            // transfer always shows the payee the bank itself recognises.
            'paystack_recipient_code' => $this->recipientCode($resolvedName, $accountNumber, $bankCode),
        ];
    }

    /** Re-run verification on an account already on file. */
    public function reverify(NotaryBankDetail $detail): NotaryBankDetail
    {
        if (! $detail->bank_code) {
            return $detail;
        }

        $detail->update($this->verify(
            $detail->notaryProfile,
            (string) $detail->account_number,
            (string) $detail->bank_code,
        ));

        return $detail;
    }

    private function recipientCode(string $name, string $accountNumber, string $bankCode): ?string
    {
        try {
            return $this->paystack->createTransferRecipient($name, $accountNumber, $bankCode);
        } catch (\Throwable $e) {
            // Verification succeeded, so the account is real; only the payout
            // handle is missing and can be created again at payout time.
            Log::warning('Transfer recipient could not be created.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Does the name on the account plausibly belong to this notary?
     *
     * Nigerian bank records routinely reorder names and drop or add middle
     * names, so an exact match is the wrong test — "OSHOBUGIE FAVOUR" and
     * "Favour Oshobugie Iyeduala" are the same person. Two shared name parts is
     * the threshold; single-token overlap ("MICHAEL") is too weak to mean
     * anything. Chambers and company accounts are checked against the
     * organization name as well.
     */
    private function looksLike(string $resolvedName, NotaryProfile $profile): bool
    {
        $account = $this->tokens($resolvedName);

        if ($account === []) {
            return false;
        }

        foreach ([$profile->user?->full_name, $profile->organization_name] as $candidate) {
            if (! $candidate) {
                continue;
            }

            $theirs = $this->tokens($candidate);
            $shared = count(array_intersect($account, $theirs));

            // Two parts, or everything the shorter name has — a mononym or a
            // two-word company name cannot produce two matches.
            if ($shared >= 2 || ($shared >= 1 && min(count($account), count($theirs)) === 1)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> Meaningful, comparable parts of a name. */
    private function tokens(string $name): array
    {
        $words = preg_split('/[^A-Za-z]+/', Str::upper($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Corporate boilerplate matches everything and proves nothing.
        $noise = ['LTD', 'LIMITED', 'PLC', 'AND', 'THE', 'NIG', 'NIGERIA', 'ENTERPRISES', 'CHAMBERS', 'ASSOCIATES'];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $w) => strlen($w) >= 3 && ! in_array($w, $noise, true),
        )));
    }
}
