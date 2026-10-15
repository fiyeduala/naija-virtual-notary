<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\EmailOtpNotification;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Handles email OTP for account verification at signup.
 *
 * The code is stored hashed (never in plaintext) and expires after a short
 * window. Verification is for confirming the account email only — there is no
 * SMS OTP in this system (see Build Plan section 2).
 *
 * Why there is an attempt counter below: the code is six digits, which is a
 * million possibilities, and the window is ten minutes. A script can walk the
 * whole space in that window if nothing is counting, so a wrong code has to
 * cost something. Two brakes do it, and they are deliberately different:
 *
 *   - A route throttle on /verify caps how fast guesses can arrive at all.
 *   - This counter caps how many *wrong codes* one account may offer, and it
 *     survives a resend. That second part is the one that matters: burning the
 *     code on the fifth failure would be pointless if asking for a fresh one
 *     handed the attacker five more guesses, so the counter is keyed on the
 *     user and is cleared only by verifying successfully.
 *
 * The lockout is on the code, not the account. Reaching the limit throws the
 * current code away and makes the user wait for the window to pass before a
 * new one will be accepted — it never disables the account, because the person
 * being inconvenienced is usually the owner mistyping, not an attacker.
 */
class OtpService
{
    /** Minutes before an issued code expires. */
    public const EXPIRY_MINUTES = 10;

    /** Length of the numeric code. */
    public const CODE_LENGTH = 6;

    /** Wrong codes allowed per account inside the decay window below. */
    public const MAX_ATTEMPTS = 5;

    /** Seconds the attempt counter remembers a failure. Matches the code's life. */
    public const ATTEMPT_DECAY_SECONDS = self::EXPIRY_MINUTES * 60;

    public function issue(User $user): void
    {
        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        $user->forceFill([
            'otp_code'       => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
        ])->save();

        $user->notify(new EmailOtpNotification($code));

        AuditLogger::record('otp.issued', 'user', $user->id, [], $user->id);
    }

    public function verify(User $user, string $code): bool
    {
        // Checked before the hash comparison, so a locked-out account costs an
        // attacker a rejection rather than a bcrypt verification per guess.
        if ($this->isLockedOut($user)) {
            return false;
        }

        if (! $user->otp_code || ! $user->otp_expires_at) {
            return false;
        }

        if ($user->otp_expires_at->isPast()) {
            return false;
        }

        if (! Hash::check($code, $user->otp_code)) {
            RateLimiter::hit($this->attemptKey($user), self::ATTEMPT_DECAY_SECONDS);

            AuditLogger::record('otp.failed', 'user', $user->id, [
                'attempts_remaining' => $this->attemptsRemaining($user),
            ], $user->id);

            // Out of attempts: throw the code away as well, so the rest of its
            // ten minutes is worth nothing even to somebody who guesses it.
            if ($this->isLockedOut($user)) {
                $user->forceFill([
                    'otp_code'       => null,
                    'otp_expires_at' => null,
                ])->save();

                AuditLogger::record('otp.locked', 'user', $user->id, [
                    'available_in' => $this->availableIn($user),
                ], $user->id);
            }

            return false;
        }

        RateLimiter::clear($this->attemptKey($user));

        $user->forceFill([
            'email_verified_at' => now(),
            'otp_code'          => null,
            'otp_expires_at'    => null,
        ])->save();

        AuditLogger::record('otp.verified', 'user', $user->id, [], $user->id);

        return true;
    }

    /** Has this account spent its wrong guesses for now? */
    public function isLockedOut(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->attemptKey($user), self::MAX_ATTEMPTS);
    }

    /** Wrong codes this account may still offer before it is locked out. */
    public function attemptsRemaining(User $user): int
    {
        return RateLimiter::remaining($this->attemptKey($user), self::MAX_ATTEMPTS);
    }

    /** Seconds until a locked-out account may try again. */
    public function availableIn(User $user): int
    {
        return RateLimiter::availableIn($this->attemptKey($user));
    }

    /**
     * Throttle helper: whether a fresh code may be issued (avoids spamming).
     *
     * A locked-out account is refused here too. Without that, the lockout
     * would only ever last as long as it takes to click "resend".
     */
    public function canResend(User $user): bool
    {
        if ($this->isLockedOut($user)) {
            return false;
        }

        if (! $user->otp_expires_at) {
            return true;
        }

        // Allow resend once the current code is within 8 minutes of expiry
        // (i.e. issued more than ~2 minutes ago).
        return $user->otp_expires_at->diffInMinutes(now(), false) >= -(self::EXPIRY_MINUTES - 2);
    }

    private function attemptKey(User $user): string
    {
        // Keyed on the account alone, not the account and the IP. Unlike login,
        // this route is behind `auth`, so only the account holder's own session
        // can reach it — there is no third party to lock out by flooding it.
        return 'otp-verify:' . $user->id;
    }
}
