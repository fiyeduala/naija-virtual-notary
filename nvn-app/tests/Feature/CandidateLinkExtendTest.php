<?php

namespace Tests\Feature;

use App\Models\CandidateLoginToken;
use App\Services\Recruitment\CandidateAccessService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\RecruitmentFixtures;
use Tests\TestCase;

/**
 * Giving time back to links that died in the host's queue.
 *
 * The failure this repairs is worth stating because it is not obvious from
 * either end: the outbox marked a message sent, the panel agreed, and the
 * candidate still had nothing — because `sent_at` is the handoff to the mail
 * server and the mail server was holding hours of backlog. A thirty-minute
 * link does not survive that, and the candidate's experience is a portal that
 * tells them their perfectly good link has expired.
 *
 * Raising the setting fixes the next link and not the last one, deliberately:
 * the expiry is written onto the row, so a link's life cannot move under it.
 * These tests are mostly about the repair being narrow — the right rows, one
 * live link per person at the end of it, and nothing re-sent.
 */
class CandidateLinkExtendTest extends TestCase
{
    use RefreshDatabase;
    use RecruitmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recruitment.enabled' => true]);
        Settings::flush();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Settings::flush();

        parent::tearDown();
    }

    private function service(): CandidateAccessService
    {
        return app(CandidateAccessService::class);
    }

    /** A candidate with one link that expired while the mail was in transit. */
    private function candidateWithDeadLink(int $agoMinutes = 90): \App\Models\Candidate
    {
        $candidate = $this->makeApplication($this->makePublishedVacancy())->candidate;

        [, $token] = $this->service()->issue($candidate);

        $token->forceFill([
            'created_at' => now()->subMinutes($agoMinutes),
            'expires_at' => now()->subMinutes($agoMinutes - 30),
        ])->save();

        return $candidate;
    }

    public function test_the_lifetime_is_stated_in_days_rather_than_minutes(): void
    {
        config(['recruitment.candidate_access.token_minutes' => 4320]);

        $this->assertSame('3 days', CandidateAccessService::lifetimeInWords());
    }

    public function test_an_exact_hour_is_stated_as_hours_and_anything_else_stays_in_minutes(): void
    {
        $this->assertSame('2 hours', CandidateAccessService::lifetimeInWords(120));
        $this->assertSame('1 day', CandidateAccessService::lifetimeInWords(1440));

        // Ninety minutes is not an hour and a half in any phrasing that is
        // also still true, so it stays as it is.
        $this->assertSame('90 minutes', CandidateAccessService::lifetimeInWords(90));
        $this->assertSame('30 minutes', CandidateAccessService::lifetimeInWords(30));
    }

    public function test_raising_the_setting_does_not_revive_a_link_already_sent(): void
    {
        $candidate = $this->candidateWithDeadLink();

        config(['recruitment.candidate_access.token_minutes' => 4320]);

        // The whole reason the repair command has to exist. The expiry is a
        // column, not a calculation, so the old link stays dead.
        $this->assertFalse($candidate->loginTokens()->sole()->isUsable());
    }

    public function test_the_command_reports_without_changing_anything(): void
    {
        $candidate = $this->candidateWithDeadLink();

        $this->artisan('nvn:candidate-link-extend')->assertSuccessful();

        $this->assertFalse($candidate->loginTokens()->sole()->fresh()->isUsable());
    }

    public function test_applying_puts_the_newest_link_back_in_service(): void
    {
        config(['recruitment.candidate_access.token_minutes' => 4320]);

        $candidate = $this->candidateWithDeadLink();

        $this->artisan('nvn:candidate-link-extend --apply')->assertSuccessful();

        $token = $candidate->loginTokens()->sole()->fresh();

        $this->assertTrue($token->isUsable());
        $this->assertTrue($token->expires_at->greaterThan(now()->addDays(2)));
    }

    public function test_the_extended_link_actually_signs_the_candidate_in(): void
    {
        config(['recruitment.candidate_access.token_minutes' => 4320]);

        $candidate = $this->makeApplication($this->makePublishedVacancy())->candidate;

        [$plain, $token] = $this->service()->issue($candidate);
        $token->forceFill(['expires_at' => now()->subHour()])->save();

        $this->assertNull($this->service()->peek($plain));

        $this->artisan('nvn:candidate-link-extend --apply')->assertSuccessful();

        // The point of the whole exercise: the link sitting in the inbox the
        // candidate already has works again, with no second email.
        $this->assertNotNull($this->service()->peek($plain));
        $this->assertTrue($candidate->is($this->service()->redeem($plain)));
    }

    public function test_only_one_link_per_candidate_is_left_live(): void
    {
        config(['recruitment.candidate_access.token_minutes' => 4320]);

        $candidate = $this->makeApplication($this->makePublishedVacancy())->candidate;

        // Three asks in a row. issue() only retires links that are still
        // live, so three expired, unconsumed rows is the realistic state — and
        // reviving all three would leave this candidate with three live links
        // when the design allows one.
        foreach ([180, 120, 60] as $agoMinutes) {
            [, $token] = $this->service()->issue($candidate);
            $token->forceFill([
                'created_at' => now()->subMinutes($agoMinutes),
                'expires_at' => now()->subMinutes($agoMinutes - 30),
            ])->save();
        }

        $this->artisan('nvn:candidate-link-extend --apply')->assertSuccessful();

        $this->assertSame(1, $candidate->loginTokens()->usable()->count());
        $this->assertSame(3, $candidate->loginTokens()->count());
    }

    public function test_a_link_already_used_is_left_alone(): void
    {
        config(['recruitment.candidate_access.token_minutes' => 4320]);

        $candidate = $this->makeApplication($this->makePublishedVacancy())->candidate;

        [$plain] = $this->service()->issue($candidate);
        $this->service()->redeem($plain);

        $this->artisan('nvn:candidate-link-extend --apply')->assertSuccessful();

        // Somebody who already signed in does not get a spent link handed back
        // to them, and a replay of that URL still fails.
        $this->assertNull($this->service()->peek($plain));
        $this->assertSame(0, $candidate->loginTokens()->usable()->count());
    }

    public function test_a_link_that_is_still_live_is_not_touched(): void
    {
        $candidate = $this->makeApplication($this->makePublishedVacancy())->candidate;

        [, $token] = $this->service()->issue($candidate);
        $before = $token->expires_at;

        $this->artisan('nvn:candidate-link-extend --apply')->assertSuccessful();

        $this->assertTrue($before->equalTo($token->fresh()->expires_at));
    }

    public function test_an_old_link_is_outside_the_window(): void
    {
        config(['recruitment.candidate_access.token_minutes' => 4320]);

        $candidate = $this->candidateWithDeadLink(agoMinutes: 60 * 24 * 10);

        $this->artisan('nvn:candidate-link-extend --apply')->assertSuccessful();

        // Ten days ago is not somebody caught by the backlog; it is somebody
        // who never came back, and reviving their link helps nobody.
        $this->assertFalse($candidate->loginTokens()->sole()->fresh()->isUsable());
    }

    public function test_the_repair_sends_nobody_an_email(): void
    {
        config(['recruitment.candidate_access.token_minutes' => 4320]);

        $this->candidateWithDeadLink();

        $this->artisan('nvn:candidate-link-extend --apply')->assertSuccessful();

        // The candidate already has the message. Writing again would be a
        // second email about a link they are holding.
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }
}
