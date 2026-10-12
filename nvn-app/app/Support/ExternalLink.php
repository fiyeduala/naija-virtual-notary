<?php

namespace App\Support;

/**
 * Candidate-supplied links: portfolios, work samples, introduction videos.
 *
 * Three things this class refuses to do, each for a reason:
 *
 *   - It never fetches the URL. Not for a title, not for a thumbnail, not to
 *     "check it works". The server fetching an address a stranger chose is
 *     server-side request forgery; `http://169.254.169.254/` is a link too.
 *   - It never produces an embed. An iframe of a third-party player on an
 *     admin screen hands that third party the reviewer's browser, and tells
 *     the candidate exactly when their video was watched.
 *   - It never renders as a bare anchor to an arbitrary scheme. `javascript:`
 *     and `data:` are links as far as an href is concerned.
 *
 * What a reviewer gets is a plain, escaped, rel="noopener noreferrer" link
 * they choose to click, which is the correct amount of trust.
 */
class ExternalLink
{
    /** Is this something we are willing to store and show? */
    public static function valid(?string $url): bool
    {
        return static::normalise($url) !== null;
    }

    /**
     * The storable form, or null.
     *
     * A missing scheme is the commonest real mistake — people type
     * "linkedin.com/in/name" — so https is assumed rather than rejected.
     */
    public static function normalise(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || mb_strlen($url) > 2048) {
            return null;
        }

        // Control characters inside a URL are how a filter gets bypassed.
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        if (! preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);

        if (! str_contains($host, '.') || str_contains($host, ' ')) {
            return null;
        }

        return $url;
    }

    /** The host, for showing a reviewer where a link goes before they click. */
    public static function host(?string $url): ?string
    {
        $normalised = static::normalise($url);

        if ($normalised === null) {
            return null;
        }

        $host = strtolower((string) parse_url($normalised, PHP_URL_HOST));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Is this an introduction-video link on one of the hosts the office
     * expects? Advisory only — an unexpected host is flagged, not refused,
     * because a reviewer can judge a link better than an allowlist can.
     */
    public static function onExpectedVideoHost(?string $url): bool
    {
        $host = static::host($url);

        if ($host === null) {
            return false;
        }

        foreach ((array) config('recruitment.intro_video.allowed_hosts', []) as $allowed) {
            $allowed = strtolower(ltrim((string) $allowed, '.'));

            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }
}
