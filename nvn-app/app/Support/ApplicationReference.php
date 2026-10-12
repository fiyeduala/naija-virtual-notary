<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The reference a candidate quotes when they email about their application.
 *
 * Shape: NVN-APP-7K2M9Q4T. Readable over the phone, so the alphabet drops the
 * characters people mishear or mistype — no O/0, no I/1, no S/5, no B/8 — and
 * it is not sequential, because a sequential reference tells anyone who has
 * one how many applications there are and lets them guess the next.
 *
 * It is an identifier for a human conversation, not a credential: knowing a
 * reference grants nothing. Everything a candidate reaches is authorized
 * against their own session.
 */
class ApplicationReference
{
    private const ALPHABET = '23467ACDEFGHJKLMNPQRTUVWXYZ';

    private const LENGTH = 8;

    /** A fresh reference. Uniqueness is enforced by the column, not by hope. */
    public static function generate(string $prefix = 'NVN-APP'): string
    {
        $body = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $prefix . '-' . $body;
    }

    /**
     * Generate until the callback says the value is free.
     *
     * The caller still relies on the unique index underneath: this loop is to
     * avoid an ugly error on a collision, not to prevent one.
     */
    public static function unique(callable $exists, string $prefix = 'NVN-APP'): string
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $candidate = static::generate($prefix);

            if (! $exists($candidate)) {
                return $candidate;
            }
        }

        // Twelve collisions on a 27^8 space means something is wrong with the
        // caller's existence check, not with the entropy.
        return $prefix . '-' . strtoupper(Str::random(self::LENGTH + 4));
    }

    /** Tidy up what somebody typed into a search box. */
    public static function normalise(string $input): string
    {
        $input = strtoupper(trim($input));

        return (string) preg_replace('/[^A-Z0-9\-]/', '', $input);
    }

    /** Does this look like one of ours? Used to route a search term. */
    public static function looksLikeReference(string $input): bool
    {
        return (bool) preg_match('/^NVN-[A-Z]{3}-[A-Z0-9]{6,14}$/', static::normalise($input));
    }
}
