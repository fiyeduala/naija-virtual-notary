<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest, served from a route rather than a static file.
 *
 * It has to be dynamic because the site icon is uploaded, not committed:
 * public/brand is gitignored, so a hardcoded path there is a broken image on
 * any machine where nobody has uploaded one — which is what put a bare letter
 * on the Home Screen instead of the mark.
 *
 * An uploaded icon is listed first and wins. The committed shield in
 * public/icons is always listed after it, because a manifest whose only icon
 * 404s makes the browser refuse to install the app at all.
 */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $icons = [];

        if ($uploaded = Branding::iconUrl()) {
            // Declared "any" only: an uploaded logo has no safe zone, and a
            // maskable claim would let the OS crop straight through it.
            $icons[] = ['src' => $uploaded, 'sizes' => 'any', 'purpose' => 'any'];
        }

        $icons[] = ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'];
        $icons[] = ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'];

        return response()->json([
            'id'               => '/',
            'name'             => config('app.name'),
            'short_name'       => 'NVN',
            'description'      => "Nigeria's #1 online notary service. Notarize documents anytime, anywhere.",
            'start_url'        => '/',
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
