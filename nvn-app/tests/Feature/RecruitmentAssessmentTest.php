<?php

namespace Tests\Feature;

use App\Enums\ApplicationStage;
use App\Exceptions\StageTransitionException;
use App\Models\AssessmentAssignment;
use App\Models\RecruitmentMessage;
use App\Services\Recruitment\ApplicationStageService;
use App\Services\Recruitment\AssessmentService;
use App\Services\Recruitment\CandidateAccessService;
use App\Support\ExternalLink;
use App\Support\RecruitmentTemplates;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\RecruitmentFixtures;
use Tests\TestCase;

/**
 * The exercise: what a candidate was given, when, and until when.
 *
 * Four claims are worth the tests more than the rest:
 *
 *   - What she was asked is frozen at the moment of invitation. A reviewer
 *     rewriting the exercise cannot change the questions somebody is halfway
 *     through, and cannot retrospectively change what she was marked against.
 *   - A missed deadline is a flag, not a verdict. Overdue is a substate of the
 *     assignment: the form shuts, because the deadline is enforced on the
 *     server and not in the browser, but the stage does not move and nobody is
 *     rejected. Late work gets in when a reviewer gives her longer, which is a
 *     person's decision with a row behind it.
 *   - One reminder, timed deliberately, and never one that would arrive after
 *     the thing it reminds you of.
 *   - A link she gives us is checked and then left alone. Nothing on this
 *     server opens it — that would be an SSRF hole and a way of telling a
 *     third party when we read her work.
 */
class RecruitmentAssessmentTest extends TestCase
{
    use RefreshDatabase, RecruitmentFixtures;

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

    /** Invited, and ready for the candidate to open. */
    private function invited(string $slug): AssessmentAssignment
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => $slug]));

        return $this->assessments->invite(
            $this->stages->beginReview($application, $reviewer),
            $reviewer,
        );
    }

    private function signIn(AssessmentAssignment $assignment): void
    {
        [$plain] = app(CandidateAccessService::class)->issue($assignment->application->candidate);

        $this->post(route('candidate.login.redeem', $plain));
    }

    public function test_the_exercise_is_frozen_at_the_moment_it_is_sent(): void
    {
        $assignment = $this->invited('frozen');
        $template    = $assignment->template;

        $this->assertSame(['one', 'two'], array_column($assignment->tasks(), 'key'));

        // A reviewer rewrites the exercise an hour later.
        $template->forceFill([
            'version' => $template->version + 1,
            'tasks'   => [
                ['key' => 'rewritten', 'label' => 'An entirely different question.', 'type' => 'long_text', 'required' => true],
            ],
            'rubric'  => [
                ['key' => 'rewritten', 'label' => 'Something else', 'anchors' => ['1' => 'No', '5' => 'Yes']],
            ],
        ])->save();

        $assignment->refresh();

        // She still sees the exercise she was given, and will be marked
        // against the rubric that came with it.
        $this->assertSame(['one', 'two'], array_column($assignment->tasks(), 'key'));
        $this->assertSame(['clarity'], array_column($assignment->rubric(), 'key'));
        $this->assertSame(1, $assignment->template_version);

        $this->signIn($assignment);

        $this->get(route('candidate.assessment', $assignment))
            ->assertOk()
            ->assertSee('The first question.')
            ->assertDontSee('An entirely different question.');
    }

    public function test_a_second_invitation_replaces_the_first_so_there_is_one_deadline(): void
    {
        $reviewer   = $this->makeRecruitmentAdmin();
        $assignment = $this->invited('one-deadline');

        $second = $this->assessments->invite($assignment->application->fresh(), $reviewer);

        $this->assertNotSame($assignment->id, $second->id);
        $this->assertSame(AssessmentAssignment::CANCELLED, $assignment->fresh()->status);

        $this->assertSame(
            1,
            $assignment->application->assignments()->open()->count(),
        );
    }

    public function test_an_invitation_delay_is_deliberate_and_defaults_to_immediate(): void
    {
        $reviewer = $this->makeRecruitmentAdmin();

        // Default: out now. Nothing waits unless somebody chose a wait.
        $now = $this->invited('immediate');

        $this->assertTrue(
            $now->application->messages()
                ->where('template_key', RecruitmentTemplates::ASSESSMENT_SENT)
                ->first()
                ->available_at
                ->lessThanOrEqualTo(now()->addMinute()),
        );

        // Two working days, chosen by an administrator. Twice from the same
        // starting point gives the same answer — the delay is a decision, not
        // a sprinkle of randomness to look human.
        $later = $this->assessments->invite(
            $this->stages->beginReview(
                $this->makeApplication($this->makePublishedVacancy(['slug' => 'delayed'])),
                $reviewer,
            ),
            $reviewer,
            ['delay_working_days' => 2],
        );

        $available = $later->application->messages()
            ->where('template_key', RecruitmentTemplates::ASSESSMENT_SENT)
            ->first()
            ->available_at;

        $this->assertTrue($available->greaterThan(now()->addDay()));
        $this->assertSame('09:00', $available->copy()->setTimezone(config('recruitment.timezone'))->format('H:i'));

        // An unknown delay is not honoured as "some other number of days".
        $odd = $this->assessments->invite(
            $this->stages->beginReview(
                $this->makeApplication($this->makePublishedVacancy(['slug' => 'odd-delay'])),
                $reviewer,
            ),
            $reviewer,
            ['delay_working_days' => 99],
        );

        $this->assertTrue(
            $odd->application->messages()
                ->where('template_key', RecruitmentTemplates::ASSESSMENT_SENT)
                ->first()
                ->available_at
                ->lessThanOrEqualTo(now()->addMinute()),
        );
    }

    public function test_one_reminder_goes_out_before_the_deadline_and_moves_with_it(): void
    {
        $reviewer   = $this->makeRecruitmentAdmin();
        $assignment = $this->invited('reminding');

        $reminder = $assignment->application->messages()
            ->where('template_key', RecruitmentTemplates::ASSESSMENT_NUDGE)
            ->get();

        $this->assertCount(1, $reminder);
        $this->assertTrue($reminder->first()->available_at->lessThan($assignment->due_at));

        // Captured now, because extend() forceFills the instance it is handed.
        // Comparing against $assignment->due_at afterwards compares the new
        // deadline with itself and passes whatever happens.
        $originalDue = $assignment->due_at->copy();

        // The deadline moves. The reminder timed for the old date is called
        // off and a fresh one is queued for the new one, so nobody is nudged
        // about a deadline that has already changed.
        $extended = $this->assessments->extend($assignment, $reviewer, 3, 'She asked, and the reason was good.');

        $this->assertSame(1, $extended->extension_count);
        $this->assertSame($reviewer->id, $extended->extended_by);
        $this->assertTrue($extended->due_at->greaterThan($originalDue));
        $this->assertTrue($extended->wasExtended());

        $nudges = $assignment->application->messages()
            ->where('template_key', RecruitmentTemplates::ASSESSMENT_NUDGE)
            ->get();

        $this->assertSame(1, $nudges->where('status', RecruitmentMessage::CANCELLED)->count());
        $this->assertSame(1, $nudges->where('status', RecruitmentMessage::PENDING)->count());

        $this->assertTrue(
            $nudges->firstWhere('status', RecruitmentMessage::PENDING)
                ->available_at
                ->lessThan($extended->due_at),
        );
    }

    public function test_a_deadline_inside_the_reminder_window_gets_no_reminder(): void
    {
        $reviewer = $this->makeRecruitmentAdmin();

        // One working day to do it, so the reminder would land at or after the
        // deadline. A nudge that arrives after the thing it nudges about is
        // worse than silence.
        $assignment = $this->assessments->invite(
            $this->stages->beginReview(
                $this->makeApplication($this->makePublishedVacancy(['slug' => 'tight'])),
                $reviewer,
            ),
            $reviewer,
            ['due_working_days' => 1],
        );

        $this->assertSame(
            0,
            $assignment->application->messages()
                ->where('template_key', RecruitmentTemplates::ASSESSMENT_NUDGE)
                ->count(),
        );
    }

    public function test_a_missed_deadline_is_a_flag_and_not_a_verdict(): void
    {
        $assignment = $this->invited('overdue');

        $assignment->forceFill(['due_at' => now()->subHours(2)])->save();

        $this->assertSame(1, $this->assessments->markOverdue());

        $assignment->refresh();

        $this->assertSame(AssessmentAssignment::OVERDUE, $assignment->status);
        $this->assertTrue($assignment->isOverdue());

        // Nothing moved her and nothing rejected her. Overdue is a fact about
        // the exercise, and the only thing it decides is that the form is shut.
        $this->assertSame(ApplicationStage::AssessmentInvited, $assignment->application->fresh()->stage);
        $this->assertTrue($assignment->application->fresh()->stage->isActive());
        $this->assertNull($assignment->application->fresh()->rejected_at);

        // The deadline is enforced here, not in the browser. A page left open
        // in a tab overnight does not extend anybody's time, and the refusal
        // says what has actually happened rather than claiming she has already
        // submitted something.
        $this->assertFalse($assignment->acceptsChanges());

        $this->signIn($assignment);

        $this->get(route('candidate.assessment', $assignment))
            ->assertOk()
            ->assertSee('The deadline for this assessment has passed');

        $this->post(route('candidate.assessment.submit', $assignment), [
            'answers' => ['one' => 'Late, but here it is.'],
        ])->assertSessionHasErrors('answers');

        $this->assertNull($assignment->fresh()->submitted_at);

        // And the way back in is a person. A reviewer gives her longer, which
        // clears the flag and reopens the form — that is the whole mechanism
        // for accepting late work, and it leaves a row saying who allowed it.
        $extended = $this->assessments->extend(
            $assignment->fresh(),
            $this->makeRecruitmentAdmin(),
            2,
            'Her power was out for two days.',
        );

        $this->assertSame(AssessmentAssignment::INVITED, $extended->status);
        $this->assertFalse($extended->isOverdue());
        $this->assertTrue($extended->acceptsChanges());

        $this->post(route('candidate.assessment.submit', $extended), [
            'answers' => ['one' => 'Late, but here it is.'],
        ])->assertRedirect(route('candidate.assessment', $extended));

        $this->assertNotNull($extended->fresh()->submitted_at);
        $this->assertSame(ApplicationStage::AssessmentSubmitted, $assignment->application->fresh()->stage);
    }

    public function test_a_finished_submission_cannot_be_edited_until_a_reviewer_reopens_it(): void
    {
        $reviewer   = $this->makeRecruitmentAdmin();
        $assignment = $this->invited('final');

        $this->signIn($assignment);

        $this->post(route('candidate.assessment.submit', $assignment), [
            'answers' => ['one' => 'My first answer.'],
        ])->assertRedirect();

        // A second attempt at the same assignment changes nothing.
        $this->post(route('candidate.assessment.submit', $assignment), [
            'answers' => ['one' => 'Actually, let me redo that.'],
        ]);

        $this->assertStringContainsString(
            'My first answer.',
            (string) $assignment->fresh()->finalAnswers()->firstWhere('task_key', 'one')?->answer,
        );

        // A reviewer may let her in again, with a reason, and the earlier
        // answer is kept rather than overwritten.
        $reopened = $this->assessments->reopen($assignment, $reviewer, 'Half her answer was cut off.', 2);

        $this->assertSame(AssessmentAssignment::REOPENED, $reopened->status);
        $this->assertTrue($reopened->acceptsChanges());

        $this->post(route('candidate.assessment.submit', $reopened), [
            'answers' => ['one' => 'The whole answer this time.'],
        ])->assertRedirect();

        $revisions = $assignment->responses()->where('task_key', 'one')->get();

        $this->assertGreaterThan(1, $revisions->count());
        $this->assertTrue($revisions->contains(fn ($r) => str_contains((string) $r->answer, 'My first answer.')));
    }

    public function test_a_required_answer_cannot_be_left_blank(): void
    {
        $assignment = $this->invited('required');

        $this->signIn($assignment);

        // 'one' is required; 'two' is not.
        $this->post(route('candidate.assessment.submit', $assignment), [
            'answers' => ['two' => 'Only the optional one.'],
        ])->assertSessionHasErrors('answers');

        $this->assertNull($assignment->fresh()->submitted_at);

        // And a draft save is forgiving, because a half-finished answer is
        // not a mistake.
        $this->post(route('candidate.assessment.draft', $assignment), [
            'answers' => ['one' => 'Half a thought so far'],
        ])->assertRedirect();

        $this->assertNull($assignment->fresh()->submitted_at);
        $this->assertSame(1, $assignment->fresh()->draftAnswers()->count());
    }

    public function test_a_video_link_is_checked_and_then_left_alone(): void
    {
        $assignment = $this->invited('intro-link');

        $this->signIn($assignment);

        // Anything that is not http(s) is refused before it reaches storage.
        foreach (['javascript:alert(1)', 'file:///etc/passwd', 'data:text/html,<script>', 'not a url'] as $bad) {
            $this->assertFalse(ExternalLink::valid($bad), $bad . ' was accepted as a link.');
        }

        $this->post(route('candidate.assessment.submit', $assignment), [
            'answers'         => ['one' => 'Here it is.'],
            'intro_video_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('intro_video_url');

        $this->assertNull($assignment->fresh()->submitted_at);

        $this->post(route('candidate.assessment.submit', $assignment), [
            'answers'         => ['one' => 'Here it is.'],
            'intro_video_url' => 'https://drive.example.test/my-intro',
        ])->assertRedirect();

        $this->assertSame('https://drive.example.test/my-intro', $assignment->fresh()->intro_video_url);
    }

    public function test_a_required_introduction_is_satisfied_in_writing(): void
    {
        $reviewer = $this->makeRecruitmentAdmin();
        $vacancy  = $this->makePublishedVacancy([
            'slug'                 => 'intro-required',
            'intro_video_enabled'  => true,
            'intro_video_required' => true,
        ]);

        $assignment = $this->assessments->invite(
            $this->stages->beginReview($this->makeApplication($vacancy), $reviewer),
            $reviewer,
        );

        $this->signIn($assignment);

        // Nothing at all: refused, because the vacancy asks for one.
        $this->post(route('candidate.assessment.submit', $assignment), [
            'answers' => ['one' => 'Done.'],
        ])->assertSessionHasErrors('intro_video_url');

        // In writing instead of on camera, and that is not a lesser answer.
        $this->post(route('candidate.assessment.submit', $assignment), [
            'answers'           => ['one' => 'Done.'],
            'intro_alternative' => 'I would rather write it, so: I have spent four years on support desks.',
        ])->assertRedirect();

        $this->assertNotNull($assignment->fresh()->submitted_at);
        $this->assertNull($assignment->fresh()->intro_video_url);
    }

    public function test_an_assessment_cannot_be_sent_to_a_closed_application(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'closed-app']));

        $this->stages->beginReview($application, $reviewer);
        $this->stages->reject($application->fresh(), $reviewer, 'Not this time.');

        $this->expectExceptionMessage('closed');

        $this->assessments->invite($application->fresh(), $reviewer);
    }

    public function test_a_vacancy_with_no_exercise_attached_says_so(): void
    {
        $reviewer = $this->makeRecruitmentAdmin();
        $vacancy  = $this->makePublishedVacancy(['slug' => 'no-exercise']);

        $vacancy->forceFill(['assessment_template_id' => null])->save();

        $application = $this->makeApplication($vacancy->fresh());

        $this->stages->beginReview($application, $reviewer);

        $this->expectExceptionMessage('no assessment attached');

        $this->assessments->invite($application->fresh(), $reviewer);
    }
}
