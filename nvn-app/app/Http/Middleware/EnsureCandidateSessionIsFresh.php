<?php

namespace App\Http\Middleware;

use App\Services\Recruitment\CandidateAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A candidate session goes cold on its own.
 *
 * The emailed link is short-lived, but the session it creates would otherwise
 * last as long as any other -- and a candidate signs in from whatever device
 * is to hand, including shared ones. So the session carries the minute it was
 * last used and is ended once that is older than the configured window;
 * signing in again costs one email.
 *
 * Idle rather than absolute, and the distinction is the whole of it. Measured
 * from the moment of signing in, the window ends mid-sentence for anybody
 * whose written exercise takes longer than it does, and they lose the screen
 * they were typing into for no reason anyone could explain to them. Measured
 * from the last thing they did, an abandoned session on a borrowed laptop
 * still goes cold on schedule, which was always the point.
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

        // Slide the window forward on every request. The stamp is what the
        // session is measured against, so re-setting it here turns "two hours
        // after you signed in" into "two hours after you last did anything" --
        // which is the difference between an abandoned session on a shared
        // machine going cold, which is the point, and a candidate being signed
        // out of a written exercise they are halfway through typing, which
        // never was.
        $request->session()->put(self::STARTED_AT, now()->timestamp);

        return $next($request);
    }
}
