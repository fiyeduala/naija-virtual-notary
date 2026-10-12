<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Where an application came from.
 *
 * The office needs to know whether the WhatsApp groups or the job boards are
 * actually producing candidates, and that answer has to survive the applicant
 * clicking around the site before applying. So the source is read from the
 * query string, remembered in the session, and stamped on the application.
 *
 * Two deliberate limits:
 *
 *   - Values are matched against an allowlist and truncated. A query string is
 *     attacker-controlled text that ends up on an admin screen and in a CSV; an
 *     unbounded one is a stored-XSS and a CSV-formula vector at once.
 *   - Nothing personal goes in a tracking URL, so the links handed to a job
 *     board carry a campaign name and nothing else. `?utm_source=whatsapp` is
 *     fine; `?ref=emeka%40example.com` is not, and would be dropped here even
 *     if somebody built such a link by hand.
 */
class SourceAttribution
{
    /** Where it is kept between the click and the submission. */
    public const SESSION_KEY = 'recruitment_source';

    /** How long a value may be once stored. */
    private const MAX_LENGTH = 40;

    /**
     * The channels the office actually advertises on.
     *
     * Anything else is recorded as `other`, so a typo in a posted link shows
     * up as an unattributed application rather than inventing a channel.
     */
    public const CHANNELS = [
        'direct'    => 'Direct / typed the address',
        'whatsapp'  => 'WhatsApp group',
        'jobberman' => 'Jobberman',
        'indeed'    => 'Indeed',
        'linkedin'  => 'LinkedIn',
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        'x'         => 'X / Twitter',
        'referral'  => 'Staff or client referral',
        'website'   => 'Our own website',
        'other'     => 'Other',
    ];

    /**
     * Read the source off a request and remember it for this visitor.
     *
     * First touch wins: somebody who arrives from a WhatsApp group, browses,
     * and comes back through a Google result a day later is still a WhatsApp
     * candidate as far as the session knows.
     *
     * @return array{channel: string, campaign: ?string, medium: ?string}
     */
    public static function capture(Request $request): array
    {
        $existing = $request->session()->get(self::SESSION_KEY);

        if (is_array($existing) && isset($existing['channel'])) {
            return static::shape($existing);
        }

        $captured = static::shape([
            'channel'  => static::channel($request->query('utm_source')),
            'campaign' => static::clean($request->query('utm_campaign')),
            'medium'   => static::clean($request->query('utm_medium')),
        ]);

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $captured);
        }

        return $captured;
    }

    /** What is remembered for this visitor right now. */
    public static function current(Request $request): array
    {
        $stored = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return static::shape(is_array($stored) ? $stored : []);
    }

    /** Map a raw utm_source onto a known channel, or `other`. */
    public static function channel(mixed $raw): string
    {
        $value = strtolower((string) static::clean($raw));

        if ($value === '') {
            return 'direct';
        }

        // A couple of spellings the office will inevitably use in a real link.
        $value = match ($value) {
            'wa', 'whats-app', 'whatsapp-group' => 'whatsapp',
            'twitter'                           => 'x',
            'fb'                                => 'facebook',
            'ig'                                => 'instagram',
            'li'                                => 'linkedin',
            default                             => $value,
        };

        return array_key_exists($value, self::CHANNELS) ? $value : 'other';
    }

    public static function label(?string $channel): string
    {
        return self::CHANNELS[$channel ?? 'direct'] ?? 'Other';
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return self::CHANNELS;
    }

    /** Strip a raw query value to printable text of a bounded length. */
    public static function clean(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = (string) preg_replace('/[^A-Za-z0-9 _.\-]/', '', trim($raw));
        $value = trim(mb_substr($value, 0, self::MAX_LENGTH));

        return $value === '' ? null : $value;
    }

    /** @return array{channel: string, campaign: ?string, medium: ?string} */
    private static function shape(array $values): array
    {
        return [
            'channel'  => static::channel($values['channel'] ?? null),
            'campaign' => static::clean($values['campaign'] ?? null),
            'medium'   => static::clean($values['medium'] ?? null),
        ];
    }
}
