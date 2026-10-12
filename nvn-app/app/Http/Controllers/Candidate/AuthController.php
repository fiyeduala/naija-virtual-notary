<?php

namespace App\Http\Controllers\Candidate;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCandidateSessionIsFresh;
use App\Services\Recruitment\CandidateAccessService;
use App\Support\RecruitmentPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Passwordless sign-in for applicants.
 *
 * The four decisions in here, each with its reason:
 *
 *   - The response to a link request is identical whether or not the address
 *     is on file. This form would otherwise answer "has this person applied
 *     here?" for anyone who typed an address into it.
 *   - The GET on a sign-in link only draws a page with a button. Corporate
 *     mail gateways and chat previews fetch every URL in an email, often
 *     before the recipient sees it; a one-use token spent by a GET is a token
 *     the candidate never gets to use. The POST is what consumes it.
 *   - The token never reaches a log, a session or an audit row — only its
 *     SHA-256 is stored at all — and the pages that show it carry
 *     `referrer: no-referrer` so it cannot leak through an outbound click.
 *   - Rate limited twice: by address, so one mailbox cannot be flooded, and by
 *     IP, so one machine cannot sweep for addresses that exist.
 */
class AuthController extends Controller
{
    public function __construct(private CandidateAccessService $access) {}

    public function show(): View
    {
        abort_unless(RecruitmentPermission::moduleEnabled(), 404);

        return view('candidate.sign-in');
    }

    public function request(Request $request): RedirectResponse
    {
        abort_unless(RecruitmentPermission::moduleEnabled(), 404);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
        ]);

        $email = (string) $validated['email'];

        // Per-address, on top of the route's per-IP throttle. Both are silent:
        // the message below is the same either way, because "too many
        // requests for that address" would itself confirm the address.
        $key = 'candidate-link:' . sha1(mb_strtolower($email));

        if (! RateLimiter::tooManyAttempts($key, 5)) {
            RateLimiter::hit($key, 600);
            $this->access->requestLink($email, $request);
        }

        return back()->with('status', $this->sentMessage());
    }

    /**
     * The confirmation page. Looks the token up, spends nothing.
     *
     * An expired or already-used link lands here too, and says so plainly with
     * a way to ask for another one, rather than showing a dead button.
     */
    public function confirm(Request $request, string $token): View
    {
        abort_unless(RecruitmentPermission::moduleEnabled(), 404);

        $record = $this->access->peek($token);

        return view('candidate.confirm', [
            'token' => $token,
            'valid' => $record !== null,
        ]);
    }

    /** The POST that actually signs them in. One use, then gone. */
    public function redeem(Request $request, string $token): RedirectResponse
    {
        abort_unless(RecruitmentPermission::moduleEnabled(), 404);

        $candidate = $this->access->redeem($token);

        if (! $candidate) {
            throw ValidationException::withMessages([
                'token' => 'That sign-in link has expired or has already been used. Ask for a new one below.',
            ]);
        }

        // A fresh session id, so a token captured alongside a pre-existing
        // session cannot be used to ride it.
        $request->session()->regenerate();

        auth('candidate')->login($candidate, false);

        $request->session()->put(EnsureCandidateSessionIsFresh::STARTED_AT, now()->timestamp);

        return redirect()->route('candidate.home');
    }

    public function logout(Request $request): RedirectResponse
    {
        auth('candidate')->logout();
        $request->session()->forget(EnsureCandidateSessionIsFresh::STARTED_AT);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('candidate.login')
            ->with('status', 'You are signed out.');
    }

    /**
     * The one message this screen gives, in every case.
     *
     * Said carefully: it describes what happens IF the address is on file,
     * without stating whether it is. The delay is mentioned because the queue
     * runs on a cron and a candidate refreshing their inbox for ten seconds
     * will otherwise conclude it is broken.
     */
    private function sentMessage(): string
    {
        return 'If that address has an application with us, a sign-in link is on its way. '
            . 'It can take a minute to arrive, and the link works once and then expires.';
    }
}
