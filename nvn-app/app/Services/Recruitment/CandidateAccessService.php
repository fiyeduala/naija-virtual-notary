<?php

namespace App\Services\Recruitment;

use App\Models\Candidate;
use App\Models\CandidateLoginToken;
use App\Support\AuditLogger;
use App\Support\EmailNormalizer;
use App\Support\RecruitmentTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * How a candidate gets into their own area, and nothing else.
 *
 * No passwords. A candidate applying for a job should not have to invent and
 * remember a credential for a site they may visit four times, and a password
 * they reuse elsewhere is a liability we would be creating for them. So: they
 * type their email address, we email a one-use link, and the link is exchanged
 * for an ordinary session on a guard of its own.
 *
 * Six decisions in here are security decisions rather than conveniences:
 *
 *   1. Only the SHA-256 of the token is stored. A copy of the database does not
 *      let anybody sign in as a candidate.
 *   2. The token is spent by a POST from a page with a button on it, never by
 *      the GET that the link itself performs. Corporate mail scanners and
 *      link-preview bots fetch every URL in an email; a token spent on GET is
 *      a token the candidate finds already used.
 *   3. Requesting a link says the same thing whether or not the address is
 *      known. "No application found for that address" tells a stranger who
 *      applied here.
 *   4. The plain token is never written to a log, an audit row or a session.
 *   5. The session is on the `candidate` guard. It is not the `web` guard, so
 *      a candidate session cannot reach a client or staff screen even by
 *      accident — there is no shared cookie identity to confuse.
 *   6. Nothing here consults or creates a `users` row. A candidate is not an
 *      account on the platform and applying for a job does not make one.
 */
class CandidateAccessService
{
    public function __construct(private RecruitmentOutbox $outbox) {}

    /**
     * Someone typed an address on the sign-in page.
     *
     * Returns nothing in every case. The caller shows the same confirmation
     * either way.
     */
    public function requestLink(string $email, ?Request $request = null): void
    {
        $canonical = EmailNormalizer::canonical($email);

        if ($canonical === '' || ! filter_var(EmailNormalizer::clean($email), FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $candidate = Candidate::query()
            ->where('email_canonical', $canonical)
            ->whereNull('anonymised_at')
            ->first();

        // No candidate, or one whose records have been erased: we stop here
        // and the page still says "check your email".
        if (! $candidate || ! $candidate->applications()->exists()) {
            return;
        }

        [$plain] = $this->issue($candidate, $request);

        $this->outbox->queueToCandidate(RecruitmentTemplates::SIGN_IN, $candidate, [
            'action_url' => route('candidate.login.confirm', ['token' => $plain]),
            'data'       => ['company' => 'Naija Virtual Notary'],
            'footnote'   => 'This link expires in '
                . (int) config('recruitment.candidate_access.token_minutes', 30)
                . ' minutes and can only be used once.',
        ]);
    }

    /**
     * Mint a token. Returns [plain, row]; the plain string exists only in
     * memory and in the email.
     *
     * @return array{0: string, 1: CandidateLoginToken}
     */
    public function issue(Candidate $candidate, ?Request $request = null): array
    {
        // 64 characters of the URL-safe alphabet: far beyond guessing, and
        // short enough that no mail client wraps it.
        $plain   = Str::random(64);
        $minutes = max(5, (int) config('recruitment.candidate_access.token_minutes', 30));

        // Only one live link at a time. A candidate who presses the button
        // twice uses the newer email; the older link stops working, which is
        // also what they expect.
        $candidate->loginTokens()->usable()->update(['consumed_at' => now()]);

        $token = $candidate->loginTokens()->create([
            'token_hash'     => CandidateLoginToken::hashFor($plain),
            'expires_at'     => now()->addMinutes($minutes),
            'requester_hash' => $request ? $this->requesterHash($request) : null,
        ]);

        AuditLogger::record('candidate_access.requested', 'candidate', $candidate->id, [
            // The masked address, not the address: an audit row is read by
            // people who have no reason to learn who applied.
            'email'      => EmailNormalizer::mask((string) $candidate->email),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ]);

        return [$plain, $token];
    }

    /**
     * Look a token up without spending it.
     *
     * This is what the confirmation page does. A scanner that fetches the link
     * gets a page with a button and changes nothing.
     */
    public function peek(?string $plain): ?CandidateLoginToken
    {
        if (! is_string($plain) || strlen($plain) < 32) {
            return null;
        }

        return CandidateLoginToken::query()
            ->usable()
            ->where('token_hash', CandidateLoginToken::hashFor($plain))
            ->with('candidate')
            ->first();
    }

    /**
     * Spend a token and return the candidate it belongs to.
     *
     * Under a lock, and the consumed_at write is the thing that makes it
     * one-use: a replayed POST finds the row already consumed and gets null.
     */
    public function redeem(?string $plain): ?Candidate
    {
        if (! is_string($plain) || strlen($plain) < 32) {
            return null;
        }

        $hash = CandidateLoginToken::hashFor($plain);

        return DB::transaction(function () use ($hash) {
            $token = CandidateLoginToken::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (! $token || ! $token->isUsable()) {
                return null;
            }

            $token->forceFill(['consumed_at' => now()])->save();

            $candidate = $token->candidate;

            if (! $candidate || $candidate->anonymised_at) {
                return null;
            }

            $candidate->forceFill(['last_login_at' => now()])->save();

            AuditLogger::record('candidate_access.signed_in', 'candidate', $candidate->id, [
                'email' => EmailNormalizer::mask((string) $candidate->email),
            ]);

            return $candidate;
        });
    }

    /** Kill every live link for this candidate — used on sign-out. */
    public function revokeAll(Candidate $candidate): int
    {
        return $candidate->loginTokens()->usable()->update(['consumed_at' => now()]);
    }

    /** Tidy up spent and expired rows. Called by the retention command. */
    public function pruneTokens(int $days = 7): int
    {
        return CandidateLoginToken::query()
            ->where(function ($query) use ($days) {
                $query->where('expires_at', '<', now()->subDays($days))
                    ->orWhere('consumed_at', '<', now()->subDays($days));
            })
            ->delete();
    }

    /**
     * A stable, non-reversible mark for who asked, for the rate limiter and
     * for spotting abuse. Never the address itself and never a raw IP.
     */
    public function requesterHash(Request $request): string
    {
        return hash('sha256', implode('|', [
            (string) config('app.key'),
            (string) $request->ip(),
        ]));
    }

    /** How long a signed-in candidate stays signed in. */
    public function sessionMinutes(): int
    {
        return max(15, (int) config('recruitment.candidate_access.session_minutes', 120));
    }

    /** Has this session been open longer than that? */
    public function sessionExpired(?int $signedInAt): bool
    {
        if (! $signedInAt) {
            return true;
        }

        return Carbon::createFromTimestamp($signedInAt)
            ->addMinutes($this->sessionMinutes())
            ->isPast();
    }
}
