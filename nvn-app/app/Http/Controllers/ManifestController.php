<?php

namespace App\Http\Controllers;

use App\Support\AppIcons;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest, served from a route rather than a static file.
 *
 * It has to be dynamic because the site icon is uploaded, not committed:
 * public/brand is gitignored, so a hardcoded path there is a broken image on
 * any machine where nobody has uploaded one — which is what put a bare letter
 * on the Home Screen instead of the mark.
 *
 * An uploaded icon wins outright, at the sizes AppIcons redraws it to. The
 * committed shield in public/icons stands in only when there is no upload to
 * use, because a manifest whose only icon 404s makes the browser refuse to
 * install the app at all.
 */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $icons = [];

        foreach (AppIcons::SIZES as $size) {
            if ($url = AppIcons::url($size)) {
                // Declared "any" only: an uploaded logo has no safe zone, and a
                // maskable claim would let the OS crop straight through it.
                $icons[] = ['src' => $url, 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'];
            }
        }

        // Only when there is no usable upload. Listing the shield alongside a
        // real icon is what made the shield win: it is an exact size match and
        // it claims maskable, so the launcher preferred it every time.
        if ($icons === []) {
            $icons[] = ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'];
            $icons[] = ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'];
        }

        return response()->json([
            'id'               => '/',
            'name'             => config('app.name'),
            'short_name'       => 'NVN',
            'description'      => "Nigeria's #1 online notary service. Notarize documents anytime, anywhere.",
            // Not the marketing site: someone who installed this did it to get
            // at their own work. /dashboard forwards each role to its own
            // screen and asks for a sign-in only if the session has lapsed.
            'start_url'        => route('dashboard', absolute: false),
            'scope'            => '/',
            'display'          => 'standalone',
            'background_color' => '#EDFBE2',
            'theme_color'      => '#54B435',
            'categories'       => ['productivity', 'business'],
            'lang'             => 'en-NG',
            'icons'            => $icons,
        ], 200, [
            'Content-Type'  => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_SLASHES);
    }
}
