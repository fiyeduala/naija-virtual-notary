<?php

namespace App\Support;

/**
 * Cloudflare's edge ranges, so the app can tell a real visitor's address from
 * the proxy that carried the request.
 *
 * Since 2026-08-07 the live subdomain is proxied by Cloudflare, which means
 * every request arrives from a Cloudflare machine and the visitor's real
 * address travels in `X-Forwarded-For`. Without this list Laravel reports the
 * proxy as the client, and three things quietly go wrong:
 *
 *  - every audit log entry records a Cloudflare address, including the
 *    `ip_address` written against a notarisation session, which is part of the
 *    record of who attended and from where;
 *  - login and password-reset throttling keys on email + IP, so unrelated
 *    people sharing an edge node share a rate limit;
 *  - `X-Forwarded-Proto` is ignored, so Laravel can decide the request was
 *    plain HTTP and generate `http://` links on an HTTPS page.
 *
 * The list is deliberately not `*`. This origin is still reachable by its own
 * IP address — the staging subdomain resolves straight to it — and trusting
 * every proxy would let anyone who found that address hand us any client IP
 * they liked in a header, writing a fabricated address into the audit trail.
 * Trusting only these ranges means a forwarded header is honoured when it
 * genuinely came through Cloudflare and ignored otherwise.
 *
 * Published at https://www.cloudflare.com/ips/ and changed rarely; if
 * Cloudflare adds a range, real addresses silently become edge addresses
 * again, so re-check this list if the audit log starts filling with
 * unfamiliar IPs.
 */
class CloudflareProxies
{
    /** @var list<string> */
    public const V4 = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    /** @var list<string> */
    public const V6 = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * @return list<string>
     */
    public static function ranges(): array
    {
        return array_merge(self::V4, self::V6);
    }
}
