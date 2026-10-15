<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Where a browser posts what the Content-Security-Policy would have blocked.
 *
 * The policy ships report-only, which means the browser enforces nothing and
 * instead tells us. Without somewhere for it to tell, report-only is a header
 * nobody ever reads — so this logs each report as a single warning line, and
 * the live log becomes the list of things to fix before the policy is
 * enforced. See App\Http\Middleware\SecurityHeaders.
 *
 * It is an unauthenticated public POST, which is a thing to be careful with,
 * so three limits apply. The route is throttled. Only the four fields worth
 * having are read, each truncated — a report can quote the offending line of
 * script, and that is somebody's page content, not ours to keep. And the
 * response is always 204 with no body, so it tells a prodding stranger
 * nothing at all.
 *
 * It accepts both shapes: application/csp-report, which is what Chrome and
 * Safari send under report-uri, and the newer Reporting API array that
 * report-to sends. Only report-uri is configured today; the array is read
 * anyway so that switching later needs no change here.
 */
class CspReportController extends Controller
{
    /** Characters kept per field. Enough to identify, too few to hoard. */
    private const MAX_FIELD_LENGTH = 300;

    public function store(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);

        if (is_array($payload)) {
            foreach ($this->reportsIn($payload) as $report) {
                $this->log($report, $request);
            }
        }

        // 204 whatever happened, including on a malformed body. A browser does
        // not read the response, and nobody else needs to learn from it.
        return response()->noContent();
    }

    /**
     * The reports in a body, whichever of the two formats it arrived in.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reportsIn(array $payload): array
    {
        // report-uri: {"csp-report": {...}}
        if (isset($payload['csp-report']) && is_array($payload['csp-report'])) {
            return [$payload['csp-report']];
        }

        // Reporting API: [{"type": "csp-violation", "body": {...}}, ...]
        $reports = [];

        foreach ($payload as $entry) {
            if (is_array($entry) && isset($entry['body']) && is_array($entry['body'])) {
                $reports[] = $entry['body'];
            }
        }

        return $reports;
    }

    private function log(array $report, Request $request): void
    {
        // Both spellings, because the Reporting API renamed every field.
        $directive = $report['effective-directive']
            ?? $report['effectiveDirective']
            ?? $report['violated-directive']
            ?? $report['violatedDirective']
            ?? 'unknown';

        $blocked = $report['blocked-uri'] ?? $report['blockedURL'] ?? 'unknown';
        $document = $report['document-uri'] ?? $report['documentURL'] ?? 'unknown';

        Log::warning('CSP report-only violation', [
            'directive' => $this->clip($directive),
            'blocked'   => $this->clip($blocked),
            'document'  => $this->clip($document),
            // Which browser, so a report that only one engine produces is
            // recognisable as such rather than chased as a real problem.
            'agent'     => $this->clip((string) $request->userAgent()),
        ]);
    }

    private function clip(mixed $value): string
    {
        return mb_substr(is_scalar($value) ? (string) $value : '', 0, self::MAX_FIELD_LENGTH);
    }
}
