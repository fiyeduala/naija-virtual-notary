<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Every applicant file passes through here on its way to the private disk.
 *
 * The rules, and why each one is here rather than left to validation:
 *
 *   - The extension AND the sniffed MIME type must both be acceptable, and
 *     they must agree with each other. A `.pdf` whose bytes say `text/html`
 *     is stored XSS waiting for whoever opens it; a `.php` renamed to `.pdf`
 *     is worse.
 *   - .docx is a zip. PHP's finfo reports `application/zip` for it as often
 *     as the long OOXML type, so zip is accepted ONLY when the extension is
 *     also .docx, which is why agreement is checked rather than a flat list.
 *   - The stored name is random. The original is kept in a column, shown
 *     escaped, and used only as the filename of an authorized download —
 *     never as a path segment, so a name cannot traverse anywhere.
 *   - Nothing is ever written under storage/app/public, so no symlink can
 *     expose a CV.
 *
 * Scanning: off unless the owner configures a scanner. An unscanned file is
 * recorded as `unscanned`, never as clean. If a scanner IS configured and the
 * scan fails to run, the upload is refused and nothing is stored — fail
 * closed, because "the scanner was broken that week" is the exact window an
 * attacker wants.
 */
class UploadGuard
{
    public const SCAN_UNSCANNED = 'unscanned';
    public const SCAN_CLEAN     = 'clean';
    public const SCAN_INFECTED  = 'infected';

    /**
     * Check and store one file.
     *
     * @param  'cv'|'onboarding'  $kind
     * @return array{path: string, original_name: string, mime: string, size: int, scan_status: string, scanned_at: ?string}
     */
    public static function store(UploadedFile $file, string $kind, string $directory): array
    {
        $rules = (array) config('recruitment.uploads.' . $kind, []);

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $allowed   = array_map('strtolower', (array) ($rules['extensions'] ?? []));

        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            throw new RuntimeException('That file type is not accepted. Allowed: ' . implode(', ', $allowed) . '.');
        }

        // Belt and braces: even if a .pdf somehow reached the allowed list, a
        // double extension like `cv.pdf.php` must never get through.
        foreach (static::nameExtensions($file->getClientOriginalName()) as $part) {
            if (in_array($part, static::forbidden(), true)) {
                throw new RuntimeException('That file type is not accepted.');
            }
        }

        $maxKb = (int) ($rules['max_kb'] ?? 5120);

        if ($file->getSize() > $maxKb * 1024) {
            throw new RuntimeException('That file is larger than ' . round($maxKb / 1024, 1) . ' MB.');
        }

        // getMimeType() sniffs the bytes; getClientMimeType() trusts the
        // browser, which is to say it trusts whoever sent the request.
        $mime       = (string) $file->getMimeType();
        $acceptable = array_map('strtolower', (array) ($rules['mimes'] ?? []));

        if (! in_array(strtolower($mime), $acceptable, true) || ! static::agrees($extension, $mime)) {
            throw new RuntimeException('That file does not look like a ' . strtoupper($extension) . ' document. Please re-save it and try again.');
        }

        $scan = static::scan($file->getRealPath());

        if ($scan === self::SCAN_INFECTED) {
            throw new RuntimeException('That file was rejected by our virus scanner.');
        }

        $name = Str::lower(Str::random(40)) . '.' . $extension;
        $path = trim($directory, '/') . '/' . $name;

        Storage::disk('private')->putFileAs(trim($directory, '/'), $file, $name);

        return [
            'path'          => $path,
            'original_name' => static::safeOriginalName($file->getClientOriginalName()),
            'mime'          => $mime,
            'size'          => (int) $file->getSize(),
            'scan_status'   => $scan,
            'scanned_at'    => $scan === self::SCAN_UNSCANNED ? null : now()->toDateTimeString(),
        ];
    }

    /** @return list<string> */
    public static function forbidden(): array
    {
        return array_map('strtolower', (array) config('recruitment.uploads.forbidden_extensions', []));
    }

    /**
     * Does the sniffed type match the extension the candidate gave it?
     *
     * The zip row is the reason this method exists: a .docx is a zip, so zip
     * is permitted for .docx and for nothing else.
     */
    private static function agrees(string $extension, string $mime): bool
    {
        $mime = strtolower($mime);

        return match ($extension) {
            'pdf'  => $mime === 'application/pdf',
            'docx' => in_array($mime, [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
                'application/x-zip-compressed',
            ], true),
            'jpg', 'jpeg' => in_array($mime, ['image/jpeg', 'image/pjpeg'], true),
            'png'  => $mime === 'image/png',
            default => false,
        };
    }

    /**
     * Every dot-separated extension in a filename, lowercased.
     *
     * @return list<string>
     */
    private static function nameExtensions(string $name): array
    {
        $parts = explode('.', strtolower($name));
        array_shift($parts);

        return array_values(array_map(
            fn ($part) => (string) preg_replace('/[^a-z0-9]/', '', $part),
            $parts,
        ));
    }

    /** The original name, stripped of anything that is not a filename. */
    public static function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\pL\pN \.\-_()]+/u', '', $name);

        return Str::limit(trim($name) ?: 'document', 120, '');
    }

    /**
     * Run the configured scanner, or report that there wasn't one.
     *
     * Three outcomes only. There is no fourth one meaning "probably fine".
     */
    public static function scan(?string $absolutePath): string
    {
        if (! config('recruitment.scanner.enabled', false)) {
            return self::SCAN_UNSCANNED;
        }

        if (! $absolutePath || ! is_readable($absolutePath)) {
            throw new RuntimeException('The uploaded file could not be read for scanning. Please try again.');
        }

        $command = trim((string) config('recruitment.scanner.command', ''));

        if ($command === '') {
            throw new RuntimeException('Virus scanning is enabled but no scanner is configured. Uploads are paused.');
        }

        try {
            $process = Process::fromShellCommandline(
                $command . ' ' . escapeshellarg($absolutePath),
                timeout: (int) config('recruitment.scanner.timeout', 60),
            );

            $process->run();
            $exit = $process->getExitCode();
        } catch (\Throwable) {
            // Deliberately not logged with the path: a path is enough to
            // identify a candidate's file in storage.
            throw new RuntimeException('The virus scanner could not be reached. Uploads are paused until it is available.');
        }

        return match ($exit) {
            0       => self::SCAN_CLEAN,
            1       => self::SCAN_INFECTED,
            default => throw new RuntimeException('The virus scanner reported an error. Uploads are paused until it is resolved.'),
        };
    }

    /** Is a scanner actually in place? Shown on the readiness screen. */
    public static function scannerAvailable(): bool
    {
        return (bool) config('recruitment.scanner.enabled', false);
    }

    /** Human wording for a stored scan status, for an admin column. */
    public static function scanLabel(?string $status): string
    {
        return match ($status) {
            self::SCAN_CLEAN    => 'Scanned, clean',
            self::SCAN_INFECTED => 'Rejected by scanner',
            default             => 'Not scanned',
        };
    }

    /** The `accept` attribute for a file input, derived from the same config. */
    public static function acceptAttribute(string $kind): string
    {
        $extensions = (array) config('recruitment.uploads.' . $kind . '.extensions', []);

        return implode(',', array_map(fn ($ext) => '.' . strtolower((string) $ext), $extensions));
    }

    /** The size limit in megabytes, for the hint under a file input. */
    public static function maxMb(string $kind): string
    {
        $kb = (int) config('recruitment.uploads.' . $kind . '.max_kb', 5120);

        return rtrim(rtrim(number_format($kb / 1024, 1), '0'), '.');
    }
}
