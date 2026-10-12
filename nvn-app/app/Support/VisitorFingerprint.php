<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Who a visitor is, to the only degree the blog needs to know.
 *
 * Analytics wants two things from an anonymous visitor: "is this the same
 * person as a moment ago" and "have they been here before". Both are answered
 * by a stable, opaque token. Neither needs an IP address on disk, so none is
 * written — the raw address stays in the request and in the web server's own
 * logs, where it already was.
 */
class VisitorFingerprint
{
    /**
     * A stable, non-reversible token for this visitor.
     *
     * Salted with APP_KEY so the digest cannot be checked against a guessed
     * address by anybody who gets a copy of the table, and so two
     * installations of this app never produce comparable hashes.
     *
     * The user agent is mixed in because a shared office or a mobile carrier
     * puts many genuinely different people behind one address; without it they
     * would collapse into a single reader. It is only ever hashed, never
     * stored, so a browser upgrade quietly makes someone look new. That is the
     * right trade: a slightly high unique-visitor count is a much smaller
     * problem than a table that fingerprints people precisely.
     */
    public static function for(Request $request): string
    {
        return hash('sha256', implode('|', [
            $request->ip(),
            (string) $request->userAgent(),
            config('app.key'),
        ]));
    }

    /**
     * A bot, a crawler or a link preview — not a reader.
     *
     * Without this every count is wrong in the same direction: Googlebot,
     * Bingbot and the preview fetchers that fire when a link is pasted into
     * WhatsApp or Slack would all read as an audience. Matching on the user
     * agent is crude and misses a bot that lies, but it catches every honest
     * one, which is the overwhelming majority of the traffic.
     *
     * A request with no user agent at all is treated as a bot: every real
     * browser sends one.
     */
    public static function isCrawler(Request $request): bool
    {
        $agent = strtolower((string) $request->userAgent());

        if ($agent === '') {
            return true;
        }

        // Substrings, not whole names: agents are long and the identifying
        // token sits anywhere in them.
        $markers = [
            'bot', 'crawler', 'spider', 'crawl', 'slurp', 'curl', 'wget',
            'python-requests', 'httpclient', 'headlesschrome', 'phantomjs',
            'facebookexternalhit', 'whatsapp', 'telegrambot', 'skypeuripreview',
            'slackbot', 'discordbot', 'twitterbot', 'linkedinbot', 'embedly',
            'preview', 'monitoring', 'pingdom', 'uptime', 'lighthouse',
            'gtmetrix', 'semrush', 'ahrefs', 'mj12', 'dotbot', 'petalbot',
        ];

        foreach ($markers as $marker) {
            if (str_contains($agent, $marker)) {
                return true;
            }
        }

        return false;
    }
}
