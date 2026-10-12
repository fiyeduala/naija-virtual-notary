<?php

namespace App\Support;

/**
 * One canonical form of an email address, for duplicate detection only.
 *
 * What this deliberately does NOT do: strip dots, or cut everything after a
 * `+`. Those tricks are specific to Gmail, and applying them everywhere is a
 * real bug — `first.last@a-company.com` and `firstlast@a-company.com` are two
 * different people on most mail servers, and refusing the second person's
 * application as a duplicate of the first is unrecoverable from their side.
 *
 * So the canonical form is only: trim, lowercase, and collapse the handful of
 * unicode look-alikes that a copy-paste out of a PDF introduces. The address
 * the candidate typed is stored verbatim alongside it and is what we write to.
 */
class EmailNormalizer
{
    /** The form stored in `candidates.email_canonical` and used in the index. */
    public static function canonical(string $email): string
    {
        $email = trim($email);

        // A paste out of Word arrives with a non-breaking space or a full-width
        // @; neither is typeable and both should match the plain version.
        $email = str_replace(
            ["\u{00A0}", "\u{200B}", "\u{FF20}", "\u{FE6B}"],
            ['', '', '@', '@'],
            $email,
        );

        $at = strrpos($email, '@');

        if ($at === false) {
            return mb_strtolower($email);
        }

        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        // The local part is case-sensitive per RFC 5321 and case-insensitive in
        // practice on every mail server anyone actually uses. Lowercasing it is
        // the pragmatic choice; it is what the rest of this application does.
        return mb_strtolower($local) . '@' . mb_strtolower(rtrim($domain, '.'));
    }

    /** The address as typed, cleaned of invisible characters but nothing else. */
    public static function clean(string $email): string
    {
        return trim(str_replace(
            ["\u{00A0}", "\u{200B}", "\u{FF20}", "\u{FE6B}"],
            ['', '', '@', '@'],
            $email,
        ));
    }

    /**
     * A masked rendering, for a screen or an audit note.
     *
     * Used so a stage-history entry can say which address was written to
     * without putting a full address into a log line.
     */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $keep   = mb_substr($local, 0, 1);

        return $keep . str_repeat('*', max(2, mb_strlen($local) - 1)) . '@' . $domain;
    }
}
