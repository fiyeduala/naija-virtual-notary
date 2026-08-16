<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Install icons, derived from the uploaded site icon.
 *
 * A browser picks its install icon by matching the sizes the manifest declares,
 * and an upload of unknown dimensions can only be declared "any". That loses to
 * a committed 192x192 every single time — which is how a site that had its own
 * icon, working everywhere else, still installed itself as the shipped shield.
 *
 * So the upload is redrawn here at the exact sizes each platform asks for, and
 * those are what the manifest offers. The shield stays as the floor for a site
 * with nothing uploaded, and for formats GD cannot read (SVG, ICO) — a manifest
 * whose only icon 404s makes the browser refuse to install at all.
 *
 * Derivation is lazy and keyed on the upload's own mtime, so replacing the icon
 * in Platform settings redraws these on the next request with nothing to run by
 * hand, and clearing it deletes them.
 */
class AppIcons
{
    /** What the manifest offers: one for the launcher, one for the splash. */
    public const SIZES = [192, 512];

    /** What iOS reads for the Home Screen. It never looks at the manifest. */
    public const APPLE = 180;

    /** @var array<int, string>|null size => path on the brand disk */
    private static ?array $memo = null;

    /** @return array<int, string> */
    public static function available(): array
    {
        return static::$memo ??= static::build();
    }

    /** A cache-busted URL for one derived size, or null if it could not be made. */
    public static function url(int $size): ?string
    {
        $path = static::available()[$size] ?? null;

        if ($path === null) {
            return null;
        }

        $disk = Storage::disk(Branding::DISK);

        return $disk->url($path) . '?v=' . $disk->lastModified($path);
    }

    /** @return array<int, string> */
    private static function build(): array
    {
        $disk   = Storage::disk(Branding::DISK);
        $sizes  = [...static::SIZES, static::APPLE];
        $source = Branding::path('site_icon');

        // No icon, or a row pointing at a file that is not there: clear the
        // derivatives out rather than leaving the old brand installed forever.
        if ($source === null || ! $disk->exists($source)) {
            foreach ($sizes as $size) {
                $disk->delete(static::name($size));
            }

            return [];
        }

        $uploaded = $disk->lastModified($source);
        $out      = [];

        foreach ($sizes as $size) {
            $name = static::name($size);

            if ($disk->exists($name) && $disk->lastModified($name) >= $uploaded) {
                $out[$size] = $name;

                continue;
            }

            if (static::render($disk->path($source), $disk->path($name), $size)) {
                $out[$size] = $name;
            }
        }

        return $out;
    }

    private static function name(int $size): string
    {
        return "app-icon-{$size}.png";
    }

    /**
     * Redraw the upload square at one size.
     *
     * Contained, not stretched: a wordmark uploaded as the icon keeps its
     * proportions instead of being squashed into the square the platforms
     * insist on. Returns false — never throws — when GD is missing or the file
     * is a format it cannot read; the caller falls back to the shield.
     */
    private static function render(string $from, string $to, int $size): bool
    {
        if (! function_exists('imagecreatetruecolor')) {
            return false;
        }

        $info = @getimagesize($from);

        if ($info === false) {
            return false;
        }

        $src = match ($info[2]) {
            IMAGETYPE_PNG  => @imagecreatefrompng($from),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($from),
            IMAGETYPE_GIF  => @imagecreatefromgif($from),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($from) : false,
            // SVG and ICO have no raster to resample.
            default        => false,
        };

        if (! $src) {
            return false;
        }

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        // iOS composites a transparent Home Screen icon onto black, which makes
        // a dark mark vanish and a light one look like a mistake. Only that one
        // gets a white card; Android handles transparency properly.
        $backdrop = $size === static::APPLE
            ? imagecolorallocate($canvas, 255, 255, 255)
            : imagecolorallocatealpha($canvas, 0, 0, 0, 127);

        imagefill($canvas, 0, 0, $backdrop);
        imagealphablending($canvas, true);

        $sw    = imagesx($src);
        $sh    = imagesy($src);
        $scale = min($size / $sw, $size / $sh);
        $dw    = max(1, (int) round($sw * $scale));
        $dh    = max(1, (int) round($sh * $scale));

        imagecopyresampled($canvas, $src, intdiv($size - $dw, 2), intdiv($size - $dh, 2), 0, 0, $dw, $dh, $sw, $sh);

        $ok = @imagepng($canvas, $to);

        imagedestroy($canvas);
        imagedestroy($src);

        return $ok;
    }
}
