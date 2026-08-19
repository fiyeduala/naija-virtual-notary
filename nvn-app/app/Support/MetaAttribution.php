<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Reads a Meta ad click out of the browser that is in front of us right now.
 *
 * Two cookies matter. _fbp is the pixel's own browser id; _fbc holds the click
 * id, and the pixel only writes it if the pixel happened to be loaded on the
 * page the advert landed on. It sometimes is not — a slow script, a blocker, a
 * client who arrives on a page the pixel is not on — so when the URL still
 * carries ?fbclid= we assemble _fbc ourselves, in the format Meta documents:
 *
 *     fb.1.<milliseconds>.<fbclid>
 *
 * Nothing here is a fingerprint. The IP and user agent go along because Meta
 * matches on them and will otherwise discard the event, and they are the same
 * two values every web server writes to its access log.
 */
class MetaAttribution
{
    /**
     * @return array<string, mixed> Empty when this browser shows no sign of an ad click.
     */
    public static function capture(Request $request): array
    {
        $fbc = $request->cookie('_fbc');
        $fbp = $request->cookie('_fbp');

        if (! $fbc && ($fbclid = $request->query('fbclid'))) {
            $fbc = 'fb.1.' . (int) (microtime(true) * 1000) . '.' . $fbclid;
        }

        // No click and no pixel cookie means this visit has nothing to do with
        // an advert. Storing the IP anyway would be collecting something we
        // have no use for, so the whole record is skipped.
        if (! $fbc && ! $fbp) {
            return [];
        }

        return array_filter([
            'fbc'        => $fbc,
            'fbp'        => $fbp,
            'ip'         => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            // The page they were on when we noticed. Meta wants an event_source_url
            // and rejects events whose URL is not on a verified domain.
            'url'        => substr($request->fullUrl(), 0, 500),
            'seen_at'    => now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Merge a fresh sighting into what was already recorded.
     *
     * Later wins for everything except the click id: the first _fbc seen is the
     * advert that actually brought them, and a later page view with a stale or
     * absent click must not overwrite it. Everything else — IP, user agent —
     * is better fresh, because it should match the browser Meta last saw.
     */
    public static function merge(?array $existing, array $fresh): array
    {
        $existing ??= [];

        if ($existing === []) {
            return $fresh;
        }

        if ($fresh === []) {
            return $existing;
        }

        $merged = array_merge($existing, $fresh);

        if (! empty($existing['fbc'])) {
            $merged['fbc'] = $existing['fbc'];
        }

        return $merged;
    }
}
