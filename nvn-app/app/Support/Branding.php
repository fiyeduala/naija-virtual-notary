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
        return static::iconUrl() ?? asset('brand/icon.png');
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
