<?php

namespace Tests\Feature;

use App\Enums\ApplicationStage;
use App\Models\CandidateLoginToken;
use App\Services\Recruitment\CandidateAccessService;
use App\Models\RecruitmentMessage;
use App\Support\RecruitmentTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Fixtures;
use Tests\Support\RecruitmentFixtures;
use Tests\TestCase;

/**
 * The candidate boundary.
 *
 * Four separate claims, and each has its own failure that matters:
 *
 *   A GET does not spend a token. Corporate mail scanners and link previewers
 *   fetch every URL in an email before the recipient sees it. A one-use token
 *   redeemed on GET is therefore burned by the employer's own security product,
 *   and the candidate's experience is that the link never worked.
 *
 *   A token is one-use, hashed at rest, and short-lived. The column holds a
 *   hash, so a database read does not yield working links.
 *
 *   A candidate session is not a user session. It reaches no client page, no
 *   notary desk and no admin panel, and a signed-in client cannot reach the
 *   candidate area either.
 *
 *   An id in a URL is not authorization. One candidate cannot open another's
 *   application by changing the number.
 */
class RecruitmentCandidateAccessTest extends TestCase
{
    use RefreshDatabase, RecruitmentFixtures, Fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recruitment.enabled' => true]);
        \App\Support\Settings::flush();
        Storage::fake('private');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        \App\Support\Settings::flush();

        parent::tearDown();
    }

    public function test_a_scanner_fetching_the_link_does_not_consume_the_token(): void
    {
        $vacancy     = $this->makePublishedVacancy(['slug' => 'scanner-safe']);
        $application = $this->makeApplication($vacancy);

        [$plain, $token] = app(CandidateAccessService::class)->issue($application->candidate);

        // Fetched three times, the way a scanner, a preview card and a cautious
        // gateway between them would.
        $this->get(route('candidate.login.confirm', $plain))->assertOk();
        $this->get(route('candidate.login.confirm', $plain))->assertOk();
        $this->get(route('candidate.login.confirm', $plain))->assertOk();

        $this->assertNull($token->fresh()->consumed_at);
        $this->assertGuest('candidate');

        // And it still works for the person who actually clicks.
        $this->post(route('candidate.login.redeem', $plain))
            ->assertRedirect(route('candidate.home'));

        $this->assertAuthenticated('candidate');
        $this->assertNotNull($token->fresh()->consumed_at);
    }

    public function test_a_token_works_once_only(): void
    {
        $vacancy     = $this->makePublishedVacancy(['slug' => 'one-use']);
        $application = $this->makeApplication($vacancy);

        [$plain] = app(CandidateAccessService::class)->issue($application->candidate);

        $this->post(route('candidate.login.redeem', $plain))
            ->assertRedirect(route('candidate.home'));

        $this->post(route('candidate.logout'));

        // A replay — a back button, a double submit, or somebody else holding
        // the same link. It comes back as a validation error on the sign-in
        // form rather than a 403, because the honest answer to a dead link is
        // "ask for another one" and that is the form that does it.
        $this->post(route('candidate.login.redeem', $plain))
            ->assertSessionHasErrors('token');

        $this->assertGuest('candidate');
    }

    public function test_an_expired_token_is_refused(): void
    {
        $vacancy     = $this->makePublishedVacancy(['slug' => 'expired']);
        $application = $this->makeApplication($vacancy);

        [$plain, $token] = app(CandidateAccessService::class)->issue($application->candidate);

        $token->forceFill(['expires_at' => now()->subMinute()])->save();

        // Still a 200, on purpose. The GET is harmless by design, so an
        // expired link renders the expired state and the box for asking again,
        // rather than bouncing somebody who did nothing wrong.
        $this->get(route('candidate.login.confirm', $plain))
            ->assertOk()
            ->assertSee('That link has expired')
            ->assertDontSee('Sign in now');

        $this->post(route('candidate.login.redeem', $plain))->assertSessionHasErrors('token');
        $this->assertGuest('candidate');
    }

    public function test_the_token_is_stored_as_a_hash_and_never_in_the_clear(): void
    {
        $vacancy     = $this->makePublishedVacancy(['slug' => 'hashed']);
        $application = $this->makeApplication($vacancy);

        [$plain, $token] = app(CandidateAccessService::class)->issue($application->candidate);

        $this->assertNotSame($plain, $token->token_hash);
        $this->assertSame(CandidateLoginToken::hashFor($plain), $token->token_hash);
        $this->assertSame(0, CandidateLoginToken::where('token_hash', $plain)->count());

        // 64 characters of URL-safe alphabet. Guessing is not a strategy.
        $this->assertGreaterThanOrEqual(48, strlen($plain));
    }

    public function test_requesting_a_link_answers_the_same_way_for_an_unknown_address(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'generic-reply']);
        $this->makeApplication($vacancy, ['email' => 'known@example.test']);

        $known = $this->post(route('candidate.login.request'), ['email' => 'known@example.test']);
        $this->flushSession();
        $stranger = $this->post(route('candidate.login.request'), ['email' => 'nobody@example.test']);

        // Identical answers. A different one would turn this form into a way
        // of asking whether a given person applied for a job.
        $this->assertSame($known->status(), $stranger->status());
        $this->assertSame(
            $known->headers->get('Location'),
            $stranger->headers->get('Location'),
        );
    }

    public function test_one_candidate_cannot_open_another_candidates_application(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'no-idor']);

        $mine   = $this->makeApplication($vacancy, ['email' => 'mine@example.test']);
        $theirs = $this->makeApplication(
            $this->makePublishedVacancy(['slug' => 'no-idor-two']),
            ['email' => 'theirs@example.test'],
        );

        $this->signInAs($mine->candidate);

        $this->get(route('candidate.application', $mine))->assertOk();

        // The id in the URL is not the authority. Changing the number gets a
        // 404, not somebody else's motivation letter.
        $this->get(route('candidate.application', $theirs))->assertNotFound();
        $this->post(route('candidate.withdraw', $theirs))->assertNotFound();

        $this->assertNull($theirs->fresh()->withdrawn_at);
    }

    public function test_a_candidate_session_reaches_no_client_or_staff_page(): void
    {
        $vacancy     = $this->makePublishedVacancy(['slug' => 'own-lane']);
        $application = $this->makeApplication($vacancy);

        $this->signInAs($application->candidate);

        // Not 200 is the whole assertion. Whether each one redirects to its own
        // login or refuses outright is that area's business; what matters is
        // that a candidate guard is not a session anywhere else.
        foreach (['/client/dashboard', '/notary/dashboard', '/admin-panel'] as $path) {
            $this->assertNotSame(200, $this->get($path)->status(), $path . ' admitted a candidate.');
        }
    }

    public function test_a_signed_in_client_cannot_reach_the_candidate_area(): void
    {
        $vacancy     = $this->makePublishedVacancy(['slug' => 'other-way']);
        $application = $this->makeApplication($vacancy);

        $this->actingAs($this->makeClient());

        $this->get(route('candidate.home'))->assertRedirect(route('candidate.login'));
        $this->get(route('candidate.application', $application))->assertRedirect(route('candidate.login'));
    }

    public function test_a_withdrawal_needs_the_candidates_own_session_and_cancels_pending_mail(): void
    {
        $vacancy     = $this->makePublishedVacancy(['slug' => 'withdrawing']);
        $application = $this->makeApplication($vacancy);

        $this->signInAs($application->candidate);

        // Posting without the tick is refused. Withdrawal is irreversible from
        // the candidate's side, so a stray click must not do it.
        $this->post(route('candidate.withdraw', $application), ['reason' => 'Mis-click.'])
            ->assertSessionHasErrors('confirm');

        $this->assertSame(ApplicationStage::Applied, $application->fresh()->stage);

        $this->post(route('candidate.withdraw', $application), [
            'confirm' => '1',
            'reason'  => 'I accepted something else.',
        ])->assertRedirect(route('candidate.application', $application));

        $application->refresh();

        $this->assertSame(ApplicationStage::Withdrawn, $application->stage);
        $this->assertNotNull($application->withdrawn_at);

        // Nothing still queued that assumes they are still in the running.
        // The acknowledgement written when they applied is cancelled with a
        // recorded reason rather than deleted, so the row still says what was
        // going to be sent and why it was not.
        $acknowledgement = $application->messages()
            ->where('template_key', RecruitmentTemplates::ACKNOWLEDGEMENT)
            ->first();

        $this->assertNotNull($acknowledgement);
        $this->assertSame(RecruitmentMessage::CANCELLED, $acknowledgement->status);

        // The one thing still queued is the confirmation OF the withdrawal.
        // That one is owed to them: somebody who has just pressed an
        // irreversible button should get something in writing saying so.
        $pending = $application->messages()
            ->where('status', RecruitmentMessage::PENDING)
            ->pluck('template_key')
            ->all();

        $this->assertSame([RecruitmentTemplates::WITHDRAWN], $pending);
    }

    public function test_internal_notes_and_scores_are_never_shown_to_the_candidate(): void
    {
        $vacancy     = $this->makePublishedVacancy(['slug' => 'private-notes']);
        $application = $this->makeApplication($vacancy);
        $reviewer    = $this->makeRecruiter();

        $application->notes()->create([
            'user_id' => $reviewer->id,
            'body'    => 'PRIVATE-REVIEWER-NOTE-DO-NOT-SHOW',
        ]);

        $this->signInAs($application->candidate);

        $this->get(route('candidate.application', $application))
            ->assertOk()
            ->assertDontSee('PRIVATE-REVIEWER-NOTE-DO-NOT-SHOW')
            ->assertDontSee($reviewer->full_name);
    }

    /** Sign a candidate in the way the redemption route does. */
    public function test_a_candidate_at_work_is_not_signed_out_at_the_end_of_the_window(): void
    {
        config(['recruitment.candidate_access.session_minutes' => 120]);

        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'still-typing']));

        $this->signInAs($application->candidate);

        // Three hours of steady work against a two-hour window. Measured from
        // the moment of signing in this is a logout in the middle of a written
        // exercise; measured from the last thing they did it is an ordinary
        // afternoon, which is the only one of the two anybody could explain.
        foreach ([90, 90] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->get(route('candidate.home'))->assertOk();
        }
    }

    public function test_a_candidate_who_walks_away_is_still_signed_out(): void
    {
        config(['recruitment.candidate_access.session_minutes' => 120]);

        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'walked-off']));

        $this->signInAs($application->candidate);

        // The borrowed-laptop case, which is the reason the window exists and
        // the thing a sliding window must not quietly give away.
        $this->travel(121)->minutes();

        $this->get(route('candidate.home'))->assertRedirect(route('candidate.login'));
    }

    private function signInAs(\App\Models\Candidate $candidate): void
    {
        [$plain] = app(CandidateAccessService::class)->issue($candidate);

        $this->post(route('candidate.login.redeem', $plain))->assertRedirect(route('candidate.home'));
    }
}
