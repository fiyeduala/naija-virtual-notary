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
 * Writing once to a cohort whose invitations arrived unusable.
 *
 * The failure being repaired: invitations were queued faster than the host
 * would relay them, so the thirty-minute sign-in link inside each one was
 * dead on arrival and most of the cohort never got in. Their deadlines then
 * ran out while they were locked out of the exercise.
 *
 * What these tests are mostly about is the follow-up being safe to run
 * against twelve hundred live records: one email each, no second email on a
 * second run, nothing sent to somebody who already submitted, and above all
 * nothing that mints a priority sign-in link — which is what would breach the
 * host's hourly cap and take the mailbox down with it.
 */
class AssessmentFollowUpTest extends TestCase
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

    /** A candidate invited to the exercise whose deadline has since run out. */
    private function strandedCandidate(string $slug = 'notary-ops'): AssessmentAssignment
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => $slug]));

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

    private function deadline(): string
    {
        return now()->addDays(5)->format('Y-m-d');
    }

    private function retryMessages(): \Illuminate\Database\Eloquent\Collection
    {
        return RecruitmentMessage::query()
            ->where('template_key', RecruitmentTemplates::ASSESSMENT_RETRY)
            ->get();
    }

    public function test_the_retry_email_is_not_priority_mail(): void
    {
        // The whole incident was a priority sign-in link outrunning the host's
        // relay. Putting the repair on the same footing would repeat it at
        // twelve hundred times the scale, and the hourly cap is enforced by
        // suspending the account.
        $this->assertFalse(RecruitmentTemplates::isPriority(RecruitmentTemplates::ASSESSMENT_RETRY));
    }

    public function test_the_command_reports_without_changing_anything(): void
    {
        $assignment = $this->strandedCandidate();

        $this->artisan('nvn:assessment-followup --deadline=' . $this->deadline())
            ->assertSuccessful();

        $this->assertSame(AssessmentAssignment::OVERDUE, $assignment->fresh()->status);
        $this->assertCount(0, $this->retryMessages());
    }

    public function test_it_refuses_to_run_without_a_deadline(): void
    {
        $this->strandedCandidate();

        $this->artisan('nvn:assessment-followup --apply')->assertFailed();
    }

    public function test_it_refuses_a_deadline_that_has_already_passed(): void
    {
        $this->strandedCandidate();

        $this->artisan('nvn:assessment-followup --apply --deadline=' . now()->subWeek()->format('Y-m-d'))
            ->assertFailed();
    }

    public function test_it_refuses_to_run_without_an_actor(): void
    {
        $this->strandedCandidate();

        // Twelve hundred deadline changes recorded against nobody is not an
        // audit trail, and extend() will not accept a null actor anyway.
        $this->artisan('nvn:assessment-followup --apply --deadline=' . $this->deadline())
            ->assertFailed();
    }

    public function test_it_refuses_an_actor_who_cannot_change_a_deadline(): void
    {
        $this->strandedCandidate();
        $outsider = $this->makeRecruitmentAdmin(['role' => 'client', 'email' => 'client@example.test']);

        $this->artisan('nvn:assessment-followup --apply --actor=' . $outsider->email
            . ' --deadline=' . $this->deadline())
            ->assertFailed();
    }

    public function test_applying_moves_the_deadline_and_queues_one_email(): void
    {
        $assignment = $this->strandedCandidate();
        $admin      = $this->makeRecruitmentAdmin();
        $target     = now()->addDays(5)->format('Y-m-d');

        $this->artisan('nvn:assessment-followup --apply --actor=' . $admin->email . ' --deadline=' . $target)
            ->assertSuccessful();

        $assignment = $assignment->fresh();

        $this->assertSame($target, $assignment->due_at->timezone('Africa/Lagos')->format('Y-m-d'));
        // Overdue is a substate of being invited, so giving the time back puts
        // them where they were rather than leaving them marked late.
        $this->assertSame(AssessmentAssignment::INVITED, $assignment->status);
        $this->assertCount(1, $this->retryMessages());
    }

    public function test_everybody_lands_on_the_same_date(): void
    {
        $admin  = $this->makeRecruitmentAdmin();
        $target = now()->addDays(6)->format('Y-m-d');

        $stranded = $this->strandedCandidate('role-a');

        // Somebody invited later whose deadline has not run out yet. They lost
        // the same days and the email tells them the same date.
        $live = $this->strandedCandidate('role-b');
        $live->forceFill([
            'invited_at' => now()->subDay(),
            'due_at'     => now()->addDay(),
            'status'     => AssessmentAssignment::INVITED,
        ])->save();

        $this->artisan('nvn:assessment-followup --apply --actor=' . $admin->email . ' --deadline=' . $target)
            ->assertSuccessful();

        foreach ([$stranded, $live] as $assignment) {
            $this->assertSame(
                $target,
                $assignment->fresh()->due_at->timezone('Africa/Lagos')->format('Y-m-d'),
            );
        }

        $this->assertCount(2, $this->retryMessages());
    }

    public function test_the_email_carries_the_sign_in_page_and_no_token(): void
    {
        $this->strandedCandidate();
        $admin = $this->makeRecruitmentAdmin();

        $this->artisan('nvn:assessment-followup --apply --actor=' . $admin->email
            . ' --deadline=' . $this->deadline())
            ->assertSuccessful();

        $payload = $this->retryMessages()->sole()->payload;

        // The door that cannot expire. A token in here would be dead again by
        // the time a paced send reached the back of the queue.
        $this->assertSame(route('candidate.login'), $payload['action_url']);
        $this->assertStringNotContainsString('token', $payload['action_url']);
    }

    public function test_running_it_twice_sends_nothing_the_second_time(): void
    {
        $assignment = $this->strandedCandidate();
        $admin      = $this->makeRecruitmentAdmin();
        $target     = now()->addDays(5)->format('Y-m-d');

        $this->artisan('nvn:assessment-followup --apply --actor=' . $admin->email . ' --deadline=' . $target)
            ->assertSuccessful();

        $countAfterFirst = $assignment->fresh()->extension_count;

        $this->artisan('nvn:assessment-followup --apply --actor=' . $admin->email . ' --deadline=' . $target)
            ->assertSuccessful();

        $this->assertCount(1, $this->retryMessages());
        // Not just one email — the deadline must not creep either.
        $this->assertSame($countAfterFirst, $assignment->fresh()->extension_count);
    }

    public function test_somebody_who_already_submitted_is_left_alone(): void
    {
        $assignment = $this->strandedCandidate();
        $assignment->forceFill([
            'status'       => AssessmentAssignment::SUBMITTED,
            'submitted_at' => now()->subHours(2),
        ])->save();

        $admin = $this->makeRecruitmentAdmin();

        $this->artisan('nvn:assessment-followup --apply --actor=' . $admin->email
            . ' --deadline=' . $this->deadline())
            ->assertSuccessful();

        $this->assertCount(0, $this->retryMessages());
    }

    public function test_a_cancelled_assessment_is_left_alone(): void
    {
        $assignment = $this->strandedCandidate();
        $assignment->forceFill([
            'status'       => AssessmentAssignment::CANCELLED,
            'cancelled_at' => now()->subHour(),
        ])->save();

        $admin = $this->makeRecruitmentAdmin();

        $this->artisan('nvn:assessment-followup --apply --actor=' . $admin->email
            . ' --deadline=' . $this->deadline())
            ->assertSuccessful();

        $this->assertCount(0, $this->retryMessages());
    }

    public function test_an_older_cohort_is_outside_the_window(): void
    {
        $assignment = $this->strandedCandidate();
        $assignment->forceFill(['invited_at' => now()->subDays(40)])->save();

        $admin = $this->makeRecruitmentAdmin();

        // Forty days ago is a different campaign, not a casualty of this one.
        $this->artisan('nvn:assessment-followup --apply --actor=' . $admin->email
            . ' --deadline=' . $this->deadline())
            ->assertSuccessful();

        $this->assertCount(0, $this->retryMessages());
    }

    public function test_the_command_sends_nothing_itself(): void
    {
        $this->strandedCandidate();
        $admin = $this->makeRecruitmentAdmin();

        $this->artisan('nvn:assessment-followup --apply --actor=' . $admin->email
            . ' --deadline=' . $this->deadline())
            ->assertSuccessful();

        // It queues. The outbox paces the send, which is the only thing
        // standing between this repair and the hourly cap.
        Mail::assertNothingSent();
        $this->assertSame(RecruitmentMessage::PENDING, $this->retryMessages()->sole()->status);
    }

    public function test_the_rendered_email_states_the_new_deadline(): void
    {
        $this->strandedCandidate();
        $admin  = $this->makeRecruitmentAdmin();
        $target = now()->addDays(5);

        $this->artisan('nvn:assessment-followup --apply --actor=' . $admin->email
            . ' --deadline=' . $target->format('Y-m-d'))
            ->assertSuccessful();

        $message  = $this->retryMessages()->sole();
        $rendered = RecruitmentTemplates::render(
            RecruitmentTemplates::ASSESSMENT_RETRY,
            $message->payload['data'],
        );

        $body = implode("\n", $rendered['paragraphs']);

        $this->assertStringContainsString($target->timezone('Africa/Lagos')->format('j F'), $body);
        // No paragraph may reach an inbox still carrying its braces.
        $this->assertStringNotContainsString('{{', $body);
    }
}
