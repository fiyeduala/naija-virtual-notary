<?php

namespace Tests\Feature;

use App\Enums\ApplicationStage;
use App\Exceptions\StageTransitionException;
use App\Models\AssessmentAssignment;
use App\Models\Offer;
use App\Models\OnboardingItem;
use App\Models\RecruitmentMessage;
use App\Services\Recruitment\ApplicationStageService;
use App\Services\Recruitment\AssessmentService;
use App\Services\Recruitment\CandidateAccessService;
use App\Services\Recruitment\InterviewService;
use App\Services\Recruitment\OfferService;
use App\Support\RecruitmentPermission;
use App\Support\RecruitmentTemplates;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\RecruitmentFixtures;
use Tests\TestCase;

/**
 * The pipeline, walked end to end and then pushed sideways.
 *
 * The first test is the demonstration asked for: one fictional candidate goes
 * from pressing Apply to being marked hired, through every stage, with a
 * person deciding each move. The tests after it are the ones that matter more
 * often — the moves that must be refused, the facts a stage is not allowed to
 * claim, and the two ways an application ends without a hire.
 *
 * What is deliberately absent from all of it: anything that advances a
 * candidate on its own. No score threshold, no automatic rejection on a missed
 * deadline, no ranking. Every assertion below about a stage change is preceded
 * by a line where somebody chose it.
 */
class RecruitmentWorkflowTest extends TestCase
{
    use RefreshDatabase, RecruitmentFixtures;

    private ApplicationStageService $stages;
    private AssessmentService $assessments;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recruitment.enabled' => true]);
        Settings::flush();
        Mail::fake();

        $this->stages      = app(ApplicationStageService::class);
        $this->assessments = app(AssessmentService::class);
    }

    protected function tearDown(): void
    {
        Settings::flush();

        parent::tearDown();
    }

    public function test_a_candidate_goes_from_applying_to_hired_with_a_person_deciding_each_step(): void
    {
        $reviewer = $this->makeRecruitmentAdmin();
        $vacancy  = $this->makePublishedVacancy(['slug' => 'end-to-end']);

        /* 1. She applies. */
        $application = $this->makeApplication($vacancy);
        $this->assertSame(ApplicationStage::Applied, $application->stage);

        /* 2. A reviewer opens it. */
        $application = $this->stages->beginReview($application, $reviewer);
        $this->assertSame(ApplicationStage::InitialReview, $application->stage);

        /* 3. The reviewer approves the exercise going out. Nothing sent it. */
        $assignment = $this->assessments->invite($application, $reviewer);

        $this->assertSame(AssessmentAssignment::INVITED, $assignment->status);
        $this->assertSame(ApplicationStage::AssessmentInvited, $application->fresh()->stage);

        // The exercise she was given is frozen onto her assignment, so the
        // template being rewritten this afternoon cannot change the questions
        // she is halfway through answering.
        $this->assertNotEmpty($assignment->template_snapshot);
        $this->assertSame($vacancy->template->version, $assignment->template_version);

        /* 4. She submits it from her own screen. */
        [$plain] = app(CandidateAccessService::class)->issue($application->candidate);
        $this->post(route('candidate.login.redeem', $plain));

        $answers = [];
        foreach ($assignment->tasks() as $task) {
            $answers[$task['key']] = 'A considered answer to ' . $task['label'] . '.';
        }

        $this->post(route('candidate.assessment.submit', $assignment), ['answers' => $answers])
            ->assertRedirect(route('candidate.assessment', $assignment));

        $this->assertNotNull($assignment->fresh()->submitted_at);
        $this->assertSame(ApplicationStage::AssessmentSubmitted, $application->fresh()->stage);

        /* 5. The reviewer marks it against the rubric she was given. */
        foreach ($assignment->fresh()->rubric() as $criterion) {
            $application->scores()->create([
                'assessment_assignment_id' => $assignment->id,
                'user_id'                  => $reviewer->id,
                'criterion_key'            => $criterion['key'],
                'criterion_label'          => $criterion['label'],
                'score'                    => 4,
                'scale_max'                => $assignment->scaleMax(),
                'comment'                  => 'Clear, and she asked the right question.',
            ]);
        }

        $this->assertSame(
            count($assignment->fresh()->rubric()),
            $application->scores()->whereNotNull('score')->count(),
        );

        /* 6. An interview is booked, with a link somebody pasted in by hand. */
        $interview = app(InterviewService::class)->schedule($application->fresh(), $reviewer, [
            'scheduled_at'           => now()->addDays(3)->setTime(11, 0),
            'duration_minutes'       => 45,
            'timezone'               => 'Africa/Lagos',
            'meeting_url'            => 'https://meet.example.test/nvn-interview',
            'candidate_instructions' => 'Bring nothing. We will talk for about forty minutes.',
        ]);

        $this->assertSame(ApplicationStage::Interview, $application->fresh()->stage);
        $this->assertSame(1, $interview->round);

        $this->assertSame(
            1,
            $application->messages()
                ->where('template_key', RecruitmentTemplates::INTERVIEW_SENT)
                ->count(),
        );

        /* 7. An offer, written by a person, approved by one, then sent. */
        $offers = app(OfferService::class);

        $offer = $offers->draft($application->fresh(), $reviewer, [
            'body'         => 'We would like you to join us. The terms are as discussed.',
            'salary_minor' => 45000000,
            'currency'     => 'NGN',
            'pay_period'   => 'month',
            'start_date'   => now()->addMonth()->toDateString(),
        ]);

        $this->assertSame(Offer::DRAFT, $offer->status);

        $offer = $offers->send($offers->approve($offer, $reviewer), $reviewer);
        $offer = $offers->recordResponse($offer, true, 'Delighted. See you then.');

        $this->assertSame(Offer::ACCEPTED, $offer->status);
        $this->assertSame(ApplicationStage::OfferChecks, $application->fresh()->stage);

        /* 8. The checks. Asked for only now, and not one of them before. */
        //
        // Accepting the offer is what built them: the checklist belongs to the
        // acceptance, not to a reviewer remembering to ask.
        $items = $application->onboardingItems()->get();

        $this->assertGreaterThan(0, $items->count());

        // A second build adds nothing. The items are keyed, so a reviewer
        // pressing "Ask for the checks" afterwards does not ask twice.
        $this->assertSame(0, $offers->buildChecklist($application->fresh(), $reviewer));

        // This vacancy asks for no identity document, so none appears. The
        // sensitive items are opt-in per role and never arrive earlier.
        $this->assertFalse($items->contains('is_sensitive', true));

        /* 9. Hired, by hand, once the checklist is clear. */
        foreach ($application->onboardingItems()->where('is_required', true)->get() as $item) {
            $item->forceFill([
                'status'      => OnboardingItem::VERIFIED,
                'verified_at' => now(),
                'verified_by' => $reviewer->id,
            ])->save();
        }

        $application = $this->stages->hire($application->fresh(), $reviewer);

        $this->assertSame(ApplicationStage::Hired, $application->stage);
        $this->assertSame($reviewer->id, $application->hired_by);

        /* And the thing a hire must NOT do. */
        $this->assertNull(\App\Models\User::where('email', $application->candidate->email)->first());

        /* The history reads as a list of people and times, in order. */
        // reorder(), because the relation is deliberately newest-first and
        // adding orderBy('id') to it only supplies a tiebreaker. Read
        // oldest-first here, which is the order a person tells the story in.
        $events = $application->stageEvents()->reorder()->orderBy('id')->get();

        $this->assertSame([
            ApplicationStage::Applied,
            ApplicationStage::InitialReview,
            ApplicationStage::AssessmentInvited,
            ApplicationStage::AssessmentSubmitted,
            ApplicationStage::Interview,
            ApplicationStage::OfferChecks,
            ApplicationStage::Hired,
        ], $events->pluck('to_stage')->all());

        // The first event is her own doing: she applied, and nothing moved her
        // there from anywhere.
        $this->assertNull($events->first()->from_stage);
        $this->assertSame('candidate', $events->first()->actor_type);

        $this->assertTrue($events->every(fn ($e) => $e->created_at !== null));
    }

    public function test_a_stage_cannot_be_skipped_however_the_request_is_crafted(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'no-skipping']));

        // Applied straight to Interview, which is two stages away. Refused on
        // the server, not merely absent from a dropdown.
        $this->expectException(StageTransitionException::class);

        $this->stages->move($application, ApplicationStage::Interview, $reviewer, [
            'reason' => 'They are very good.',
        ]);
    }

    public function test_a_stage_cannot_claim_a_submission_that_does_not_exist(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'no-claiming']));

        $this->assessments->invite($this->stages->beginReview($application, $reviewer), $reviewer);

        // The pipeline allows this move. The facts do not: she has not sent
        // anything in, so the stage is not allowed to say she has.
        $this->expectExceptionMessage('has not submitted an assessment');

        $this->stages->move(
            $application->fresh(),
            ApplicationStage::AssessmentSubmitted,
            $reviewer,
        );
    }

    public function test_a_hire_needs_an_accepted_offer_behind_it(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'no-hiring']));

        $this->forceStage($application, ApplicationStage::OfferChecks);

        $this->expectExceptionMessage('Mark the offer accepted before hiring');

        $this->stages->hire($application->fresh(), $reviewer);
    }

    public function test_a_hire_waits_for_the_checklist(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'checklist-first']));

        $this->forceStage($application, ApplicationStage::OfferChecks);

        $application->offers()->create([
            'status'     => Offer::ACCEPTED,
            'body'       => 'As discussed.',
            'created_by' => $reviewer->id,
        ]);

        $application->onboardingItems()->create([
            'item_key'     => 'confirm_terms',
            'label'        => 'Confirm you accept the terms as issued',
            'kind'         => OnboardingItem::KIND_CONFIRMATION,
            'is_required'  => true,
            'is_sensitive' => false,
            'status'       => OnboardingItem::PENDING,
        ]);

        $this->expectExceptionMessage('still outstanding');

        $this->stages->hire($application->fresh(), $reviewer);
    }

    public function test_going_backwards_or_reopening_needs_a_written_reason(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'reasons']));

        $this->forceStage($application, ApplicationStage::AssessmentInvited);

        // Backwards, with nothing said about why.
        try {
            $this->stages->move($application->fresh(), ApplicationStage::InitialReview, $reviewer);
            $this->fail('A backwards move was allowed with no reason recorded.');
        } catch (StageTransitionException $e) {
            $this->assertStringContainsString('needs a reason', $e->getMessage());
        }

        // The same move, with one.
        $moved = $this->stages->move($application->fresh(), ApplicationStage::InitialReview, $reviewer, [
            'reason' => 'Sent to the wrong candidate — my error.',
        ]);

        $this->assertSame(ApplicationStage::InitialReview, $moved->stage);
        $this->assertSame(
            'Sent to the wrong candidate — my error.',
            $moved->stageEvents()->latest('id')->first()->reason,
        );
    }

    public function test_a_reviewer_without_the_ability_cannot_move_anybody(): void
    {
        // A reviewer may read and score. Moving somebody through the pipeline
        // is a separate ability, and this role does not hold it.
        $reviewer    = $this->makeRecruiter(RecruitmentPermission::REVIEWER);
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'no-moving']));

        $this->assertFalse(
            RecruitmentPermission::allows($reviewer, RecruitmentPermission::MOVE_STAGE),
        );

        $this->expectExceptionMessage('do not have permission');

        $this->stages->beginReview($application, $reviewer);
    }

    public function test_the_same_move_pressed_twice_produces_one_event(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'double-press']));

        $this->stages->beginReview($application, $reviewer);
        $this->stages->beginReview($application->fresh(), $reviewer);

        // Two colleagues pressed the same button. The second is a no-op rather
        // than an error, and the history records the move once — alongside the
        // "applied" event the application was born with.
        $this->assertSame(1, $application->stageEvents()->whereNotNull('from_stage')->count());
        $this->assertSame(2, $application->stageEvents()->count());
        $this->assertSame(ApplicationStage::InitialReview, $application->fresh()->stage);
    }

    public function test_every_move_records_who_did_it_and_what_it_moved_from(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'attribution']));

        $this->stages->beginReview($application, $reviewer);

        $event = $application->stageEvents()->latest('id')->first();

        $this->assertSame('staff', $event->actor_type);
        $this->assertSame($reviewer->id, $event->actor_user_id);
        // Cast to the enum on the way out, so the history cannot drift into a
        // stage name that no longer exists.
        $this->assertSame(ApplicationStage::Applied, $event->from_stage);
        $this->assertSame(ApplicationStage::InitialReview, $event->to_stage);
        $this->assertNotNull($event->created_at);
    }

    public function test_the_rejection_path_keeps_the_reason_internal_and_calls_off_queued_mail(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'declining']));

        $this->stages->beginReview($application, $reviewer);
        $this->assessments->invite($application->fresh(), $reviewer);

        $application = $this->stages->reject(
            $application->fresh(),
            $reviewer,
            'Not enough written English for a role that is almost entirely written.',
        );

        $this->assertSame(ApplicationStage::Rejected, $application->stage);
        $this->assertNotNull($application->rejected_at);
        $this->assertTrue($application->stage->isTerminal());

        // The invitation and its reminder are called off, with the reason
        // recorded against the rows rather than the rows being deleted.
        $cancelled = $application->messages()
            ->where('status', RecruitmentMessage::CANCELLED)
            ->pluck('template_key')
            ->all();

        $this->assertContains(RecruitmentTemplates::ASSESSMENT_SENT, $cancelled);
        $this->assertContains(RecruitmentTemplates::ASSESSMENT_NUDGE, $cancelled);

        // She is told she was unsuccessful. She is not told what a reviewer
        // wrote about her for colleagues.
        $outgoing = $application->messages()
            ->where('template_key', RecruitmentTemplates::REJECTED)
            ->first();

        $this->assertNotNull($outgoing);

        // The payload is what the mailable will render from. The reviewer's
        // words are not in it, and there is nowhere else for them to travel:
        // the internal reason lives on the application, not on the message.
        $this->assertStringNotContainsString(
            'written English',
            (string) json_encode($outgoing->payload),
        );
    }

    public function test_a_rejected_application_can_be_reopened_with_a_reason_and_lands_in_review(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'reopening']));

        $this->stages->beginReview($application, $reviewer);
        $this->stages->reject($application->fresh(), $reviewer, 'Declined in error.');

        $reopened = $this->stages->move($application->fresh(), ApplicationStage::InitialReview, $reviewer, [
            'reason' => 'Declined in error — the wrong row was ticked.',
        ]);

        $this->assertSame(ApplicationStage::InitialReview, $reopened->stage);

        // Nothing is left behind claiming she is still rejected.
        $this->assertNull($reopened->rejected_at);
        $this->assertNull($reopened->rejection_reason);
    }

    public function test_closing_a_vacancy_leaves_its_candidates_where_they_are(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $vacancy     = $this->makePublishedVacancy(['slug' => 'closing']);
        $application = $this->makeApplication($vacancy);

        $this->stages->beginReview($application, $reviewer);

        // Closing the advert is about the advert. Deciding four people's
        // applications is a separate choice somebody makes explicitly — the
        // close action asks, and "keep" is its default.
        $vacancy->forceFill([
            'state'     => \App\Enums\VacancyState::Closed->value,
            'closed_at' => now(),
        ])->save();

        $application->refresh();

        $this->assertSame(ApplicationStage::InitialReview, $application->stage);
        $this->assertTrue($application->stage->isActive());
        $this->assertNull($application->rejected_at);
        // The advert itself is shut.
        $this->assertFalse($vacancy->fresh()->acceptsApplications());

        // And both answers to "what about the people already in it" exist as
        // choices, so closing cannot mean one of them by default.
        $this->assertArrayHasKey(\App\Models\Vacancy::CLOSE_KEEP, \App\Models\Vacancy::CLOSE_ACTIONS);
        $this->assertArrayHasKey(\App\Models\Vacancy::CLOSE_REJECT, \App\Models\Vacancy::CLOSE_ACTIONS);
    }
}
