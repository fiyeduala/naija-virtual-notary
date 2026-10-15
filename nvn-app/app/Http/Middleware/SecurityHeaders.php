<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers that tell a browser what this site is allowed to do.
 *
 * None of these fixes a bug in the application. What they do is remove the
 * second half of somebody else's attack: a cross-site scripting hole is only
 * worth finding if the injected script can reach the attacker's own server,
 * and a stolen session is only worth chasing if our sign-in page can be framed
 * under somebody else's buttons. The headers are cheap, and every one of them
 * was absent before.
 *
 * Applied twice, because this application has two front doors and they do not
 * share a middleware stack: appended to the `web` group in bootstrap/app.php,
 * and listed again in AdminPanelProvider, whose panel assembles its own stack
 * out of individual middleware rather than using that group.
 *
 * The Content-Security-Policy is the one with teeth and so the one that breaks
 * things. It ships in report-only mode: the browser enforces nothing and
 * instead posts what it *would* have blocked to /csp-report, which is logged.
 * Watch that log, correct whatever the policy got wrong, and only then set
 * NVN_CSP_ENFORCE=true. Enforcing a policy before collecting its reports is
 * how a payment page quietly stops working.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        // Do not guess at a type. Without this a browser may decide a file we
        // stored as an image is really HTML and run it — which is most of why
        // an upload directory inside the web root is dangerous at all.
        $headers->set('X-Content-Type-Options', 'nosniff');

        // No other site may frame ours. Said twice: here for older browsers,
        // and as frame-ancestors in the policy below.
        $headers->set('X-Frame-Options', 'SAMEORIGIN');

        // A link out to another site carries our origin and never the path. A
        // notarization URL holds a reference and an id, and neither belongs in
        // another company's access log.
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Switch off the device APIs nothing here uses. Camera, microphone and
        // display-capture are deliberately *absent*: a virtual notarization is
        // a video call, the Daily frame is cross-origin, and naming them here
        // would mean listing every room origin exactly — get one wrong and the
        // session fails with the camera blocked. The iframe's own allow
        // attribute is what governs those three.
        $headers->set('Permissions-Policy', implode(', ', [
            'geolocation=()',
            'payment=()',
            'usb=()',
            'serial=()',
            'bluetooth=()',
            'magnetometer=()',
            'gyroscope=()',
            'accelerometer=()',
            'idle-detection=()',
        ]));

        // A window we open cannot reach back into this one. Allow-popups so
        // that anything opened in a new tab still behaves normally.
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');

        // Only over HTTPS: a browser ignores HSTS on a plain HTTP response and
        // sending it in local development would be noise. Subdomains are
        // excluded by default — this app lives on a subdomain of a domain that
        // hosts other things, and including them makes a promise on behalf of
        // sites this code knows nothing about.
        if ($request->secure()) {
            $maxAge = (int) config('nvn.security.hsts_max_age');

            if ($maxAge > 0) {
                $headers->set('Strict-Transport-Security', 'max-age=' . $maxAge
                    . (config('nvn.security.hsts_include_subdomains') ? '; includeSubDomains' : ''));
            }
        }

        if ($policy = static::contentSecurityPolicy()) {
            $headers->set(
                config('nvn.security.csp_enforce')
                    ? 'Content-Security-Policy'
                    : 'Content-Security-Policy-Report-Only',
                $policy
            );
        }

        return $response;
    }

    /**
     * The policy, built rather than written out, because one of the origins in
     * it is configurable and a hard-coded copy would be wrong the day it is
     * changed. Every entry below is something this application actually loads;
     * the list was read out of the views, not off a template.
     */
    public static function contentSecurityPolicy(): ?string
    {
        if (! config('nvn.security.csp_enabled')) {
            return null;
        }

        // The Daily SDK comes from whatever DAILY_JS_URL points at — unpkg by
        // default, a pinned copy elsewhere if that is set — so the origin is
        // read back out of the URL rather than assumed.
        $dailyJs = static::originOf((string) config('video.daily.js_url'));

        $directives = [
            // Nothing is allowed unless a directive below says so.
            'default-src' => ["'self'"],

            // 'unsafe-eval' is Alpine, which Livewire and the entire Filament
            // panel are built on: Alpine compiles its x- attributes with new
            // Function(). 'unsafe-inline' is the inline <script> blocks in the
            // session and tracking partials. Both weaken the policy and both
            // are what the application genuinely needs today — removing them
            // is a refactor, not a setting.
            'script-src' => array_filter([
                "'self'", "'unsafe-inline'", "'unsafe-eval'",
                'https://cdnjs.cloudflare.com',    // pdf.js and mammoth.js on the session screen
                'https://embed.tawk.to', 'https://*.tawk.to',
                'https://connect.facebook.net',    // the Meta pixel, when it is switched on
                $dailyJs,
            ]),

            'style-src' => [
                "'self'", "'unsafe-inline'",
                'https://fonts.googleapis.com', 'https://fonts.bunny.net',
                'https://embed.tawk.to', 'https://*.tawk.to',
            ],

            'font-src' => [
                "'self'", 'data:',
                'https://fonts.gstatic.com', 'https://fonts.bunny.net',
                'https://*.tawk.to',
            ],

            // blob: is the document preview, which draws a page of a PDF onto
            // a canvas and hands it back as a blob URL.
            'img-src' => [
                "'self'", 'data:', 'blob:',
                'https://www.facebook.com',
                'https://*.tawk.to', 'https://*.daily.co',
            ],

            // wss: the video call's signalling socket, and the chat widget's.
            'connect-src' => array_filter([
                "'self'", 'blob:',
                'https://api.daily.co', 'https://*.daily.co', 'wss://*.daily.co',
                'https://*.tawk.to', 'wss://*.tawk.to',
                'https://connect.facebook.net', 'https://www.facebook.com',
                $dailyJs,
            ]),

            // The Daily room, the chat widget, and our own sandboxed previews.
            'frame-src' => ["'self'", 'blob:', 'https://*.daily.co', 'https://*.tawk.to'],

            'media-src'  => ["'self'", 'blob:', 'https://*.daily.co'],
            'worker-src' => ["'self'", 'blob:'],

            // Nothing here embeds a plugin.
            'object-src' => ["'none'"],

            // An injected <base> repoints every relative URL on the page,
            // including the form that takes a password.
            'base-uri' => ["'self'"],

            // Where a form may post. Paystack is reached by redirect, never by
            // posting to it, so 'self' is the whole list.
            'form-action' => ["'self'"],

            // Who may frame us — the modern X-Frame-Options.
            'frame-ancestors' => ["'self'"],
        ];

        $policy = [];

        foreach ($directives as $name => $values) {
            $policy[] = $name . ' ' . implode(' ', array_unique($values));
        }

        if ($reportUri = config('nvn.security.csp_report_uri')) {
            $policy[] = 'report-uri ' . $reportUri;
        }

        return implode('; ', $policy);
    }

    /** "https://unpkg.com/@daily-co/daily-js" to "https://unpkg.com", or null. */
    private static function originOf(string $url): ?string
    {
        $parts = parse_url($url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host']
            . (empty($parts['port']) ? '' : ':' . $parts['port']);
    }
}
