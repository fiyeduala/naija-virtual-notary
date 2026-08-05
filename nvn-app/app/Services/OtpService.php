<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\EmailOtpNotification;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Hash;

/**
 * Handles email OTP for account verification at signup.
 *
 * The code is stored hashed (never in plaintext) and expires after a short
 * window. Verification is for confirming the account email only — there is no
 * SMS OTP in this system (see Build Plan section 2).
 */
class OtpService
{
    /** Minutes before an issued code expires. */
    public const EXPIRY_MINUTES = 10;

    /** Length of the numeric code. */
    public const CODE_LENGTH = 6;

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
        if (! $user->otp_code || ! $user->otp_expires_at) {
            return false;
        }

        if ($user->otp_expires_at->isPast()) {
            return false;
        }

        if (! Hash::check($code, $user->otp_code)) {
            AuditLogger::record('otp.failed', 'user', $user->id, [], $user->id);
            return false;
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'otp_code'          => null,
            'otp_expires_at'    => null,
        ])->save();

        AuditLogger::record('otp.verified', 'user', $user->id, [], $user->id);

        return true;
    }

    /** Throttle helper: whether a fresh code may be issued (avoids spamming). */
    public function canResend(User $user): bool
    {
        if (! $user->otp_expires_at) {
            return true;
        }

        // Allow resend once the current code is within 8 minutes of expiry
        // (i.e. issued more than ~2 minutes ago).
        return $user->otp_expires_at->diffInMinutes(now(), false) >= -(self::EXPIRY_MINUTES - 2);
    }
}
