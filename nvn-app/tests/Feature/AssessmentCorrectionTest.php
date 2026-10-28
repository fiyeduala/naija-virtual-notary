<?php

namespace Tests\Feature;

use App\Models\AssessmentAssignment;
use App\Models\CandidateLoginToken;
use App\Models\RecruitmentMessage;
use App\Services\Recruitment\ApplicationStageService;
use App\Services\Recruitment\AssessmentService;
use App\Support\RecruitmentTemplates;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\RecruitmentFixtures;
use Tests\TestCase;

/**
 * Correcting one false sentence in an email that has already been read.
 *
 * The retry email told candidates the link in the earlier invitation worked
 * again. It did not and could not: a sign-in token is one use, and minting a
 * new link marks every older one spent. The template was fixed, but by then
 * two and a half thousand copies had gone out carrying the wrong sentence.
 *
 * What these tests are about is the correction reaching exactly the people it
 * misled: not the ones whose copy left after the fix, not the ones who have
 * since signed in and are inside the exercise, not twice, and never naming a
 * deadline that has already passed.
 */
class AssessmentCorrectionTest extends TestCase
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

    /** An open exercise whose retry email went out two days ago. */
    private function misledCandidate(string $slug = 'notary-ops'): RecruitmentMessage
    {
        $reviewer    = $this->makeRecruitmentAdmin(['email' => 'admin-' . $slug . '@example.test']);
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => $slug]));

        $assignment = $this->assessments->invite(
            $this->stages->beginReview($application, $reviewer),
            $reviewer,
        );

        $assignment->forceFill([
            'invited_at' => now()->subDays(4),
            'due_at'     => now()->addDays(4),
            'status'     => AssessmentAssignment::INVITED,
        ])->save();

        return $this->retrySentAt($assignment->fresh(), now()->subDays(2));
    }

    /** A retry email of the kind nvn:assessment-followup queues, already sent. */
    private function retrySentAt(AssessmentAssignment $assignment, \Illuminate\Support\Carbon $at): RecruitmentMessage
    {
        $application = $assignment->application;
        $outbox      = app(\App\Services\Recruitment\RecruitmentOutbox::class);

        $message = $outbox->queue(RecruitmentTemplates::ASSESSMENT_RETRY, $application, [
            'event_key'  => $outbox->eventKey(
                RecruitmentTemplates::ASSESSMENT_RETRY,
                $application,
                (string) $assignment->id,
            ),
            'action_url' => route('candidate.login'),
            'data'       => $outbox->baseData($application) + [
                'deadline' => $outbox->deadlineFor($application, $assignment->due_at),
            ],
            'guard'      => ['assignment' => $assignment->id, 'before_due' => true],
        ]);

        $message->forceFill([
            'status'  => RecruitmentMessage::SENT,
            'sent_at' => $at,
        ])->save();

        return $message->fresh();
    }

    private function corrections(): \Illuminate\Database\Eloquent\Collection
    {
        return RecruitmentMessage::query()
            ->where('template_key', RecruitmentTemplates::ASSESSMENT_FIX)
            ->get();
    }

    private function before(): string
    {
        return now()->subDay()->format('Y-m-d H:i');
    }

    public function test_the_correction_is_not_priority_mail(): void
    {
        // Twenty-five hundred priority sends is how the original incident
        // started. A correction for it must not be sent the same way.
        $this->assertFalse(RecruitmentTemplates::isPriority(RecruitmentTemplates::ASSESSMENT_FIX));
    }

    public function test_the_template_does_not_repeat_the_false_claim(): void
    {
        $body = RecruitmentTemplates::body(RecruitmentTemplates::ASSESSMENT_FIX);

        $this->assertStringContainsString('will not', $body);
        $this->assertStringNotContainsString('works again', $body);
    }

    public function test_the_command_reports_without_queueing_anything(): void
    {
        $this->misledCandidate();

        $this->artisan('nvn:assessment-correction --before="' . $this->before() . '"')
            ->assertSuccessful();

        $this->assertCount(0, $this->corrections());
    }

    public function test_it_refuses_to_run_without_a_cut_off(): void
    {
        $this->misledCandidate();

        // Guessing when the deploy landed either corrects somebody who was
        // never misled or misses somebody who was.
        $this->artisan('nvn:assessment-correction --apply')->assertFailed();
    }

    public function test_it_refuses_a_cut_off_in_the_future(): void
    {
        $this->misledCandidate();

        $this->artisan('nvn:assessment-correction --apply --before=' . now()->addWeek()->format('Y-m-d'))
            ->assertFailed();
    }

    public function test_it_refuses_to_run_without_an_actor(): void
    {
        $this->misledCandidate();

        $this->artisan('nvn:assessment-correction --apply --before="' . $this->before() . '"')
            ->assertFailed();
    }

    public function test_it_refuses_an_actor_who_cannot_write_to_a_cohort(): void
    {
        $this->misledCandidate();
        $outsider = $this->makeRecruitmentAdmin(['role' => 'client', 'email' => 'client@example.test']);

        $this->artisan('nvn:assessment-correction --apply --actor=' . $outsider->email
            . ' --before="' . $this->before() . '"')
            ->assertFailed();
    }

    public function test_applying_queues_one_correction(): void
    {
        $this->misledCandidate();
        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        $this->assertCount(1, $this->corrections());
    }

    public function test_running_it_twice_queues_nothing_the_second_time(): void
    {
        $this->misledCandidate();
        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        foreach (range(1, 2) as $ignored) {
            $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
                . ' --before="' . $this->before() . '"')
                ->assertSuccessful();
        }

        $this->assertCount(1, $this->corrections());
    }

    public function test_somebody_whose_retry_left_after_the_fix_is_left_alone(): void
    {
        $retry = $this->misledCandidate();
        $retry->forceFill(['sent_at' => now()->subHours(2)])->save();

        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        // Their copy rendered the corrected template. Telling them an email
        // they received was wrong, when it was not, is its own false claim.
        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        $this->assertCount(0, $this->corrections());
    }

    public function test_a_retry_still_waiting_in_the_outbox_is_left_alone(): void
    {
        $retry = $this->misledCandidate();
        $retry->forceFill([
            'status'  => RecruitmentMessage::PENDING,
            'sent_at' => null,
        ])->save();

        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        $this->assertCount(0, $this->corrections());
    }

    public function test_somebody_who_has_signed_in_since_is_left_alone(): void
    {
        $retry = $this->misledCandidate();

        CandidateLoginToken::query()->create([
            'candidate_id' => $retry->candidate_id,
            'token_hash'   => hash('sha256', Str::random(40)),
            'expires_at'   => now()->subDays(1)->addMinutes(30),
            'consumed_at'  => now()->subDay(),
        ]);

        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        // A consumed token is the only proof a sign-in happened, and it means
        // the sentence about the dead link cost this person nothing.
        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        $this->assertCount(0, $this->corrections());
    }

    public function test_a_sign_in_from_before_the_retry_does_not_count(): void
    {
        $retry = $this->misledCandidate();

        CandidateLoginToken::query()->create([
            'candidate_id' => $retry->candidate_id,
            'token_hash'   => hash('sha256', Str::random(40)),
            'expires_at'   => now()->subDays(5),
            'consumed_at'  => now()->subDays(5),
        ]);

        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        // They signed in before the retry email went out, so they read the
        // false sentence afterwards and still need the correction.
        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        $this->assertCount(1, $this->corrections());
    }

    public function test_an_unconsumed_token_does_not_count_as_a_sign_in(): void
    {
        $retry = $this->misledCandidate();

        CandidateLoginToken::query()->create([
            'candidate_id' => $retry->candidate_id,
            'token_hash'   => hash('sha256', Str::random(40)),
            'expires_at'   => now()->addMinutes(30),
            'consumed_at'  => null,
        ]);

        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        // Asking for a link is not getting in. This is the person most in
        // need of the correction, not least.
        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        $this->assertCount(1, $this->corrections());
    }

    public function test_somebody_who_already_submitted_is_left_alone(): void
    {
        $retry = $this->misledCandidate();
        $retry->application->assignments()->first()->forceFill([
            'status'       => AssessmentAssignment::SUBMITTED,
            'submitted_at' => now()->subHours(3),
        ])->save();

        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        $this->assertCount(0, $this->corrections());
    }

    public function test_a_cancelled_assessment_is_left_alone(): void
    {
        $retry = $this->misledCandidate();
        $retry->application->assignments()->first()->forceFill([
            'status'       => AssessmentAssignment::CANCELLED,
            'cancelled_at' => now()->subHour(),
        ])->save();

        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        $this->assertCount(0, $this->corrections());
    }

    public function test_a_deadline_that_has_already_passed_is_left_alone(): void
    {
        $retry = $this->misledCandidate();
        $retry->application->assignments()->first()->forceFill([
            'due_at' => now()->subDay(),
            'status' => AssessmentAssignment::OVERDUE,
        ])->save();

        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        // The email says the deadline has not changed. Sending that about a
        // deadline that has gone is a worse email than the one being fixed.
        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        $this->assertCount(0, $this->corrections());
    }

    public function test_the_correction_carries_the_sign_in_page_and_no_token(): void
    {
        $this->misledCandidate();
        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        $payload = $this->corrections()->sole()->payload;

        $this->assertSame(route('candidate.login'), $payload['action_url']);
        $this->assertStringNotContainsString('token', $payload['action_url']);
    }

    public function test_the_correction_is_guarded_on_the_deadline(): void
    {
        $retry = $this->misledCandidate();
        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        $correction = $this->corrections()->sole();
        $assignment = $retry->application->assignments()->first();

        $this->assertSame($assignment->id, $correction->payload['guard']['assignment']);
        $this->assertTrue($correction->payload['guard']['before_due']);

        // Proof the guard bites: a deadline that passes while the correction
        // waits in the queue cancels it rather than delivering it.
        $assignment->forceFill(['due_at' => now()->subHour()])->save();

        $this->assertNotNull(
            app(\App\Services\Recruitment\RecruitmentOutbox::class)->obsoleteReason($correction->fresh()),
        );
    }

    public function test_the_rendered_correction_states_the_unchanged_deadline(): void
    {
        $retry = $this->misledCandidate();
        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        $rendered = RecruitmentTemplates::render(
            RecruitmentTemplates::ASSESSMENT_FIX,
            $this->corrections()->sole()->payload['data'],
        );

        $body = implode("\n", $rendered['paragraphs']);
        $due  = $retry->application->assignments()->first()->due_at;

        $this->assertStringContainsString($due->timezone('Africa/Lagos')->format('j F'), $body);
        $this->assertStringNotContainsString('{{', $body);
        $this->assertStringNotContainsString('{{', $rendered['subject']);
    }

    public function test_the_command_sends_nothing_itself(): void
    {
        $this->misledCandidate();
        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(RecruitmentMessage::PENDING, $this->corrections()->sole()->status);
    }

    public function test_two_candidates_get_one_correction_each(): void
    {
        $this->misledCandidate('role-a');
        $this->misledCandidate('role-b');

        $admin = $this->makeRecruitmentAdmin(['email' => 'hr@example.test']);

        $this->artisan('nvn:assessment-correction --apply --actor=' . $admin->email
            . ' --before="' . $this->before() . '"')
            ->assertSuccessful();

        $this->assertCount(2, $this->corrections());
        $this->assertCount(2, $this->corrections()->pluck('candidate_id')->unique());
    }
}
