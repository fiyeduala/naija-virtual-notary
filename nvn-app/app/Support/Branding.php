<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The site's logo and icon.
 *
 * Both are optional. Every caller must cope with them being absent, because for
 * most of this application's life they were: the public navbar fell back to the
 * site name in text and the app navbar drew an inline SVG shield. Those
 * fallbacks are still the answer when nothing has been uploaded — a broken
 * image in the header of a notarization service looks worse than no image.
 *
 * The files live on the 'brand' disk, which is public/brand, so they load
 * without depending on the storage symlink. See config/filesystems.php.
 */
class Branding
{
    public const DISK = 'brand';

    /** Full-width wordmark for the navbar and the sign-in card. */
    public static function logoUrl(): ?string
    {
        return static::url('site_logo');
    }

    /** Square mark for the browser tab and the admin panel. */
    public static function iconUrl(): ?string
    {
        return static::url('site_icon');
    }

    public static function hasLogo(): bool
    {
        return static::logoUrl() !== null;
    }

    /** What the browser tab should point at, with the old static file as backstop. */
    public static function faviconUrl(): string
    {
        return static::iconUrl() ?? asset('icons/icon-32.png');
    }

    /**
     * What iOS puts on the Home Screen.
     *
     * Safari never reads the manifest's icon list for this — it reads
     * apple-touch-icon and nothing else, and with no such tag it screenshots
     * the page or draws the first letter of the title, which is where the bare
     * "D" came from. The committed shield is the floor, so there is always a
     * real image even before anyone uploads branding.
     *
     * It wants a real 180px square, so this is the derived copy rather than the
     * upload itself — which also means an SVG icon, fine in the browser tab but
     * unreadable to iOS, lands on the shield instead of back on the "D".
     */
    public static function homeScreenIconUrl(): string
    {
        return AppIcons::url(AppIcons::APPLE) ?? asset('icons/apple-touch-icon.png');
    }

    /**
     * The image a push notification shows beside its text.
     *
     * Absolute, because a service worker resolves it against its own scope and
     * the notification may be drawn long after the tab that made it is gone.
     * Every push pointed at /brand/icon-192.png, which has never existed on any
     * machine — a 404 here is silent, the OS just draws its own default.
     */
    public static function pushIconUrl(): string
    {
        return AppIcons::url(192) ?? asset('icons/icon-192.png');
    }

    /** The stored path, as Filament's FileUpload wants it. */
    public static function path(string $key): ?string
    {
        $path = Settings::string($key, '');

        return $path === '' ? null : $path;
    }

    /**
     * A URL for a stored path, or null if the row points at a file that is not
     * there — which is what a half-finished host migration looks like.
     */
    private static function url(string $key): ?string
    {
        $path = static::path($key);

        if ($path === null || ! Storage::disk(static::DISK)->exists($path)) {
            return null;
        }

        // Cache-busted on the file's own mtime: re-uploading a logo under the
        // same name should not leave every visitor looking at the old one.
        return Storage::disk(static::DISK)->url($path)
            . '?v=' . Storage::disk(static::DISK)->lastModified($path);
    }
}
