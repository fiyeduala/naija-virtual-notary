<?php

namespace App\Support;

use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Where an image dropped into the blog editor goes.
 *
 * Filament's RichEditor has no accepted-types or max-size option for its
 * attachments — the only hook is the one that stores the file — so the check
 * that a FileUpload field would have made has to be made here instead, and the
 * disk makes it worth making. Attachments land on the 'blog' disk, which is
 * public/blog-media, which the web server reads straight off disk; whatever
 * arrives is a file any visitor can fetch at a URL on this origin.
 *
 * Two things follow. The type is decided from the file's contents, never from
 * the name the browser sent, so "picture.php" cannot become a PHP file by
 * claiming to be one. And the list is short on purpose: PNG, JPEG and WebP.
 * Not PDF, not HTML, and in particular not SVG — an SVG is an XML document
 * that may carry <script>, and one served from this origin is a stored
 * cross-site scripting hole on the panel's own domain. An illustration inside
 * an article is not worth that.
 *
 * public/blog-media/.htaccess refuses to execute anything there as a second
 * line, for the host rather than the application. Both exist because either
 * alone is a single point of failure: a dotfile is what a host move forgets,
 * and this class is what the next upload path forgets to call.
 */
class BlogAttachment
{
    /** Detected MIME type to the extension the file is stored under. */
    public const ALLOWED = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /** Kilobytes. Matches the cover image field on the same form. */
    public const MAX_KILOBYTES = 4096;

    /**
     * A path that is deliberately not a file.
     *
     * Refusing is awkward here: Filament asks for the stored path and then
     * turns it into a URL, and there is no documented way to say "no". An
     * exception becomes a 500 inside the editor's upload request, where the
     * admin sees a browser error and no reason. So a refusal returns this
     * instead — Filament looks for it, does not find it, and returns no URL,
     * which means nothing is written into the article. The admin is told why
     * by the notification raised alongside it.
     */
    public const REFUSED = '__attachment-refused__';

    /** Returns the stored path, or REFUSED if the file is not one we take. */
    public static function store(TemporaryUploadedFile $file): string
    {
        $mime = static::normalise((string) $file->getMimeType());

        if ($rejection = static::rejectionFor($mime, (int) $file->getSize())) {
            return static::refuse(...$rejection);
        }

        // Random name rather than the uploaded one: the filename is part of a
        // public URL, and an article's illustration should not publish whatever
        // the file happened to be called on somebody's desktop.
        $name = Str::random(40) . '.' . static::ALLOWED[$mime];

        return $file->storeAs('inline', $name, ['disk' => 'blog', 'visibility' => 'public']);
    }

    /**
     * Why this file is not acceptable, or null if it is.
     *
     * Kept apart from store() so the decision itself can be put under test
     * without assembling a Livewire upload first. What is worth proving here
     * is the table — that PHP, HTML, SVG and PDF are all refused, and that
     * the size limit is applied — not the plumbing that reaches it.
     *
     * @return array{0: string, 1: string}|null [title, body] for the admin
     */
    public static function rejectionFor(string $mime, int $bytes): ?array
    {
        if (! array_key_exists(static::normalise($mime), static::ALLOWED)) {
            return [
                'That file cannot be attached',
                'Articles take PNG, JPEG or WebP images only.',
            ];
        }

        if ($bytes > static::MAX_KILOBYTES * 1024) {
            return [
                'That image is too large',
                'The limit for an attachment is ' . (int) (static::MAX_KILOBYTES / 1024) . 'MB.',
            ];
        }

        return null;
    }

    /** "IMAGE/PNG " and "image/png" are the same claim. */
    public static function normalise(string $mime): string
    {
        return strtolower(trim($mime));
    }

    private static function refuse(string $title, string $body): string
    {
        Notification::make()
            ->danger()
            ->title($title)
            ->body($body)
            ->send();

        return static::REFUSED;
    }
}
