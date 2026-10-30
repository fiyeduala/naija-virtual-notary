<?php

namespace Tests\Feature;

use App\Models\AssessmentAssignment;
use App\Models\RecruitmentMessage;
use App\Services\Recruitment\ApplicationStageService;
use App\Services\Recruitment\AssessmentService;
use App\Support\RecruitmentTemplates;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\RecruitmentFixtures;
use Tests\TestCase;

/**
 * Reminding a cohort that has not submitted, and the sign-out trap.
 *
 * The thing being worked around: a sign-in link lives half an hour and the
 * host's mail queue can hold an email for longer than that, so a candidate who
 * signs out and asks for a new link often gets one that is dead before they
 * open it. The session is idle-timed and slides forward, so a candidate who
 * simply does not sign out never has to ask for a second link at all — which
 * makes "do not sign out" the one piece of advice that actually keeps somebody
 * in their exercise today, whatever is done about the token's lifetime later.
 *
 * So these tests hold down two things. That the reminder says it: the template
 * is editable, and the guidance disappearing is the kind of regression nothing
 * else would notice. And that the command is safe against a live cohort: one
 * email each, no second one on a second run, nothing to somebody who already
 * has a reminder waiting, and no minted token anywhere near it.
 */
class AssessmentDeadlineTest extends TestCase
{
    use RefreshDatabase;
    use RecruitmentFixtures;

    private AssessmentService $assessments;
    private ApplicationStageService $stages;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recruitment.enabled' => true]);
        Settings::flush();
        Mail::fake();

        $this->assessments = app(AssessmentService::class);
        $this->stages      = app(ApplicationStageService::class);
    }

    protected function tearDown(): void
    {
        Settings::flush();

        parent::tearDown();
    }

    // ──────────────────────────── The wording ──────────────────────────────

    /**
     * The reminder is paced, not priority.
     *
     * It is a cohort-sized send and nobody is sitting on a page waiting for
     * it. Exempting it from the rate limit is how the mailbox got suspended.
     */
    public function test_the_reminder_is_not_priority_mail(): void
    {
        $this->assertFalse(RecruitmentTemplates::isPriority(RecruitmentTemplates::ASSESSMENT_NUDGE));
    }

    /** The advice itself, which is the reason this email is being sent. */
    public function test_the_reminder_tells_them_not_to_sign_out(): void
    {
        $body = RecruitmentTemplates::body(RecruitmentTemplates::ASSESSMENT_NUDGE);

        $this->assertStringContainsString('do not sign out', $body);
        $this->assertStringContainsString('does not expire', $body);
        $this->assertStringContainsString('newest link works', $body);
    }

    /**
     * And not to close the tab without saving.
     *
     * Nothing on the exercise page is saved as you type: the draft route is a
     * POST behind a button. A closed tab does not sign anybody out — the
     * session cookie outlives the window — but it does lose whatever was
     * typed since the last save, so the button is named in the email.
     */
    public function test_the_reminder_names_the_save_button(): void
    {
        $body = RecruitmentTemplates::body(RecruitmentTemplates::ASSESSMENT_NUDGE);

        $this->assertStringContainsString('close the tab', $body);
        $this->assertStringContainsString(
            'Save and come back later',
            $body,
            'The email quotes the button on the exercise page; it must still say that.',
        );

        $this->assertStringContainsString(
            'Save and come back later',
            file_get_contents(resource_path('views/candidate/assessment.blade.php')),
            'The exercise page no longer has the button the reminder tells people to press.',
        );
    }

    /**
     * Every placeholder in the reminder is one the command fills.
     *
     * renderText() drops any paragraph still holding braces rather than
     * letting literal `{{ }}` reach an inbox — which is right, and which means
     * adding a placeholder nobody supplies deletes a paragraph silently. The
     * guidance above is in the body for a reason; this is what stops it being
     * thrown away by an unrelated edit.
     */
    public function test_every_placeholder_in_the_reminder_is_supplied(): void
    {
        $assignment = $this->stranded();
        $this->clearQueuedReminders();

        $this->runCommand(['--apply' => true, '--actor' => $this->actorEmail()]);

        $data = (array) ($this->reminders()->first()->payload['data'] ?? []);

        preg_match_all(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            RecruitmentTemplates::body(RecruitmentTemplates::ASSESSMENT_NUDGE),
            $found,
        );

        $this->assertNotEmpty($found[1]);
        $this->assertSame(
            [],
            array_values(array_diff(array_unique($found[1]), array_keys($data))),
            'The reminder asks for a placeholder the command does not supply, so its paragraph is dropped.',
        );

        $rendered = RecruitmentTemplates::render(RecruitmentTemplates::ASSESSMENT_NUDGE, $data);

        $this->assertStringContainsString(
            'do not sign out',
            implode("\n", $rendered['paragraphs']),
            'The advice was dropped at render time.',
        );
    }

    // ──────────────────────────── Who gets one ──────────────────────────────

    public function test_without_apply_nothing_is_written(): void
    {
        $this->stranded();
        $this->clearQueuedReminders();

        $this->runCommand()->assertExitCode(0);

        $this->assertSame(0, $this->reminders()->count());
    }

    public function test_it_queues_a_reminder_for_somebody_with_none_waiting(): void
    {
        $assignment = $this->stranded();
        $this->clearQueuedReminders();

        $this->runCommand(['--apply' => true, '--actor' => $this->actorEmail()]);

        $this->assertSame(1, $this->reminders()->count());
        $this->assertSame(
            $assignment->job_application_id,
            $this->reminders()->first()->job_application_id,
        );
    }

    /**
     * A reminder already in the outbox is left to go out on its own.
     *
     * It renders from the same template at send time, so it will carry the
     * same advice. A second copy would be volume for nothing, and volume is
     * what the host suspends the account for.
     */
    public function test_a_candidate_who_already_has_one_waiting_gets_no_second_copy(): void
    {
        $assignment = $this->stranded();
        $this->clearQueuedReminders();

        // A deadline far enough out that the automatic reminder is still ahead
        // of us, which is the ordinary case this command must not duplicate.
        $assignment->forceFill([
            'due_at' => now()->addDays(10)->setTime(17, 0),
            'status' => AssessmentAssignment::INVITED,
        ])->save();

        $this->assessments->queueReminder($assignment->fresh());
        $this->assertSame(1, $this->reminders()->count());

        $this->runCommand(['--apply' => true, '--actor' => $this->actorEmail()]);

        $this->assertSame(1, $this->reminders()->count());
    }

    public function test_a_second_run_queues_nothing(): void
    {
        $this->stranded();
        $this->clearQueuedReminders();

        $this->runCommand(['--apply' => true, '--actor' => $this->actorEmail()]);
        $queued = $this->reminders()->count();

        // The first run leaves a pending reminder, so the second is held off
        // by that; cancel it and the stable event key must hold on its own.
        $this->reminders()->each(fn ($message) => $message->forceFill([
            'status' => RecruitmentMessage::CANCELLED,
        ])->save());

        $this->runCommand(['--apply' => true, '--actor' => $this->actorEmail()]);

        $this->assertSame($queued, $this->reminders()->count());
    }

    public function test_a_submitted_exercise_is_left_alone(): void
    {
        $assignment = $this->stranded();
        $this->clearQueuedReminders();

        $assignment->forceFill([
            'status'       => AssessmentAssignment::SUBMITTED,
            'submitted_at' => now()->subHour(),
        ])->save();

        $this->runCommand(['--apply' => true, '--actor' => $this->actorEmail()]);

        $this->assertSame(0, $this->reminders()->count());
    }

    // ──────────────────────────── What it carries ───────────────────────────

    /**
     * The constant page, never a token.
     *
     * A minted sign-in link is priority mail that expires in half an hour, so
     * putting one in a cohort-sized send is both the thing that breaches the
     * hourly cap and the thing that arrives dead. The login page is the same
     * address every time and cannot go stale in a queue.
     */
    public function test_the_reminder_carries_the_sign_in_page_and_no_token(): void
    {
        $this->stranded();
        $this->clearQueuedReminders();

        $this->runCommand(['--apply' => true, '--actor' => $this->actorEmail()]);

        $message = $this->reminders()->first();

        $this->assertSame(route('candidate.login'), $message->payload['action_url']);
        $this->assertStringNotContainsString('token', (string) $message->payload['action_url']);
        $this->assertSame(RecruitmentTemplates::ASSESSMENT_NUDGE, $message->template_key);
    }

    /** Submitting while it waits cancels it rather than delivering it. */
    public function test_it_is_guarded_on_the_assignment(): void
    {
        $assignment = $this->stranded();
        $this->clearQueuedReminders();

        $this->runCommand(['--apply' => true, '--actor' => $this->actorEmail()]);

        $guard = (array) ($this->reminders()->first()->payload['guard'] ?? []);

        $this->assertSame($assignment->id, $guard['assignment']);
        $this->assertTrue($guard['before_due']);
    }

    // ──────────────────────────── The deadline ──────────────────────────────

    /**
     * Left alone by default, and the email says each candidate's own date.
     *
     * Which is what makes the email honest without anybody being moved: the
     * date in it came off the record rather than off the command line.
     */
    public function test_without_move_the_email_quotes_the_candidates_own_deadline(): void
    {
        $assignment = $this->stranded();

        // Later than the cohort's date, so there is nothing --move would do
        // to it and the only date available to the email is its own.
        $own = now()->addMonths(2)->setTime(17, 0);
        $assignment->forceFill(['due_at' => $own, 'status' => AssessmentAssignment::INVITED])->save();
        $this->clearQueuedReminders();

        $this->runCommand(['--apply' => true, '--actor' => $this->actorEmail()]);

        $this->assertSame(
            $own->format('Y-m-d H:i'),
            $assignment->fresh()->due_at->format('Y-m-d H:i'),
            'The deadline moved without --move.',
        );

        $this->assertStringContainsString(
            $own->copy()->setTimezone($this->zone())->format('j F Y'),
            (string) $this->reminders()->first()->payload['data']['deadline'],
        );
    }

    public function test_move_puts_an_early_deadline_on_the_named_date(): void
    {
        $assignment = $this->stranded();
        $this->clearQueuedReminders();

        $this->runCommand([
            '--apply' => true,
            '--move'  => true,
            '--actor' => $this->actorEmail(),
        ]);

        $this->assertSame(
            $this->deadline(),
            $assignment->fresh()->due_at->setTimezone($this->zone())->format('Y-m-d'),
        );

        // The email is told the date the record now holds, not the one the
        // command was given — the two agree only because the move happened.
        $this->assertStringContainsString(
            \Carbon\CarbonImmutable::parse($this->deadline(), $this->zone())->format('j F Y'),
            (string) $this->reminders()->first()->payload['data']['deadline'],
        );
    }

    /** A later deadline is never pulled back to the cohort's date. */
    public function test_move_does_not_shorten_a_longer_deadline(): void
    {
        $assignment = $this->stranded();
        $later      = now()->addDays(20)->setTime(17, 0);

        $assignment->forceFill(['due_at' => $later, 'status' => AssessmentAssignment::INVITED])->save();
        $this->clearQueuedReminders();

        $this->runCommand([
            '--apply' => true,
            '--move'  => true,
            '--actor' => $this->actorEmail(),
        ]);

        $this->assertSame(
            $later->format('Y-m-d'),
            $assignment->fresh()->due_at->format('Y-m-d'),
        );
    }

    // ──────────────────────────── Who may run it ────────────────────────────

    public function test_an_actor_is_required_with_apply(): void
    {
        $this->stranded();
        $this->clearQueuedReminders();

        $this->runCommand(['--apply' => true])->assertExitCode(1);

        $this->assertSame(0, $this->reminders()->count());
    }

    public function test_a_past_deadline_is_refused(): void
    {
        $this->stranded();

        $this->artisan('nvn:assessment-deadline', [
            '--deadline' => now()->subWeek()->format('Y-m-d'),
        ])->assertExitCode(1);
    }

    // ──────────────────────────────── Helpers ───────────────────────────────

    /** A candidate invited to the exercise whose deadline has run out. */
    private function stranded(): AssessmentAssignment
    {
        $reviewer    = $this->makeRecruitmentAdmin(['email' => $this->actorEmail()]);
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'notary-ops']));

        $assignment = $this->assessments->invite(
            $this->stages->beginReview($application, $reviewer),
            $reviewer,
        );

        $assignment->forceFill([
            'invited_at' => now()->subDays(4),
            'due_at'     => now()->subDay(),
            'status'     => AssessmentAssignment::OVERDUE,
        ])->save();

        return $assignment->fresh();
    }

    /**
     * The condition the command exists for: nobody has a reminder coming.
     *
     * Inviting queues one automatically, and in the live cohort it was an
     * extension that cancelled them — this is that state, reached directly.
     */
    private function clearQueuedReminders(): void
    {
        $this->reminders()->each(fn ($message) => $message->delete());
    }

    private function reminders(): \Illuminate\Database\Eloquent\Collection
    {
        return RecruitmentMessage::query()
            ->where('template_key', RecruitmentTemplates::ASSESSMENT_NUDGE)
            ->get();
    }

    private function actorEmail(): string
    {
        return 'deadline.actor@example.test';
    }

    /** The business clock every deadline in an email is written in. */
    private function zone(): string
    {
        return (string) config('recruitment.timezone', 'Africa/Lagos');
    }

    /** The date the cohort is measured against: a Monday in November. */
    private function deadline(): string
    {
        return now()->addMonth()->startOfMonth()->next(\Carbon\CarbonInterface::MONDAY)->format('Y-m-d');
    }

    private function runCommand(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('nvn:assessment-deadline', $options + [
            '--deadline' => $this->deadline(),
        ]);
    }
}
