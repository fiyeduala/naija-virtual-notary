<?php

namespace App\Http\Middleware;

use App\Services\Recruitment\CandidateAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A candidate session expires on its own.
 *
 * The emailed link is short-lived, but the session it creates would otherwise
 * last as long as any other — and a candidate signs in from whatever device is
 * to hand, including shared ones. So the session carries the minute it started
 * and is ended once it is older than the configured window; signing in again
 * costs one email.
 *
 * Also the one place a candidate who was anonymised by a retention purge is
 * turned away, since their records no longer exist to show them.
 */
class EnsureCandidateSessionIsFresh
{
    public const STARTED_AT = 'candidate_session_started_at';

    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->user('candidate');

        if (! $candidate) {
            return $next($request);
        }

        $startedAt = $request->session()->get(self::STARTED_AT);
        $expired   = app(CandidateAccessService::class)->sessionExpired(
            is_numeric($startedAt) ? (int) $startedAt : null,
        );

        if ($expired || $candidate->isAnonymised()) {
            auth('candidate')->logout();
            $request->session()->forget(self::STARTED_AT);
            $request->session()->regenerate();

            return redirect()->route('candidate.login')
                ->with('status', 'You have been signed out. Ask for a new sign-in link to carry on.');
        }

        return $next($request);
    }
}
