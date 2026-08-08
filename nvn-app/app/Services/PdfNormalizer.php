<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Rewrites a modern PDF into one FPDI's free parser can open.
 *
 * FPDI understands PDF 1.4. Since 1.5 the cross-reference table may be stored
 * as a compressed stream and objects may be packed into object streams, and the
 * bundled parser refuses both — which is why an ordinary document exported by
 * Word or Google Docs arrives and cannot be sealed. The paid FPDI PDF-Parser
 * reads them; so, free of charge, does either of two command line tools that
 * most servers already carry.
 *
 * Neither is required. Where neither exists the caller refuses the document
 * with an explanation, which is the honest outcome — better than a stack trace
 * in front of a notary who is mid-session with a client waiting.
 *
 * What comes back is a NEW file in the system temp directory. The client's
 * upload is never modified: it is the thing the whole evidence trail hangs off,
 * and rewriting it to make our own sealing easier would be quietly destroying
 * the record to save ourselves a step. The caller deletes the copy afterwards.
 */
class PdfNormalizer
{
    /** Resolved once per process — probing costs a process launch each time. */
    private static ?string $tool = null;
    private static bool $probed = false;

    /**
     * A path to an importable copy, or null if this server cannot make one.
     */
    public function normalize(string $path): ?string
    {
        $tool = $this->tool();

        if ($tool === null || ! is_readable($path)) {
            return null;
        }

        $target = tempnam(sys_get_temp_dir(), 'nvn_pdf14_') . '.pdf';

        $command = $tool === 'qpdf'
            // Unpack the object streams and declare the older version, so the
            // xref is written the classic way rather than as a stream.
            ? [config('nvn.pdf.qpdf'), '--object-streams=disable', '--force-version=1.4', $path, $target]
            // pdfwrite re-emits the page content at the requested level. It
            // rewrites rather than rasterises, so the text stays text.
            : [
                config('nvn.pdf.ghostscript'), '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER',
                '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4',
                '-sOutputFile=' . $target, $path,
            ];

        $result = Process::timeout(120)->run($command);

        // qpdf exits 3 on warnings — a file it grumbled about is still a file,
        // and refusing it here would reject documents that seal perfectly well.
        $usable = ($result->successful() || $result->exitCode() === 3)
            && is_file($target)
            && filesize($target) > 0;

        if (! $usable) {
            Log::warning('PDF normalisation failed', [
                'tool'      => $tool,
                'exit_code' => $result->exitCode(),
                'error'     => mb_substr($result->errorOutput(), 0, 500),
            ]);

            @unlink($target);

            return null;
        }

        return $target;
    }

    /** 'qpdf', 'gs', or null. */
    public function tool(): ?string
    {
        if (self::$probed) {
            return self::$tool;
        }

        self::$probed = true;

        // Shared hosting sometimes disables the function Symfony's Process
        // needs. Asking first turns a fatal error into a null.
        if (! function_exists('proc_open')) {
            return self::$tool = null;
        }

        // qpdf first: it rewrites the container and leaves the page content
        // byte-for-byte, where Ghostscript regenerates it. On a document
        // somebody may later have to stand behind, the smaller change wins.
        foreach (['qpdf' => 'nvn.pdf.qpdf', 'gs' => 'nvn.pdf.ghostscript'] as $name => $key) {
            $binary = (string) config($key);

            if ($binary === '') {
                continue;
            }

            try {
                if (Process::timeout(10)->run([$binary, '--version'])->successful()) {
                    return self::$tool = $name;
                }
            } catch (\Throwable) {
                // Not installed, not executable, blocked by the host — all the
                // same answer here: try the next one.
            }
        }

        return self::$tool = null;
    }

    /** Testing seam — forget what was probed. */
    public static function flush(): void
    {
        self::$probed = false;
        self::$tool   = null;
    }
}
