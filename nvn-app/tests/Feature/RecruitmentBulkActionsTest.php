<?php

namespace Tests\Feature;

use App\Enums\ApplicationStage;
use App\Filament\Resources\JobApplicationResource\Pages\ListJobApplications;
use App\Models\JobApplication;
use App\Support\RecruitmentPermission;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\RecruitmentFixtures;
use Tests\TestCase;

/**
 * Working a pile of applications a page at a time.
 *
 * Doing this one candidate at a time is what the desk actually complained
 * about, and the complaint is fair: somebody who has read twenty applications
 * and made up their mind should not have to press twenty buttons. So there are
 * bulk actions. What these tests hold them to is that "bulk" changes how many
 * rows a decision is applied to and nothing else about it:
 *
 *   - Every candidate is still checked on their own. One who cannot move does
 *     not stop the rest, and does not quietly disappear either — they are
 *     named, with the reason the service gave.
 *   - The state machine still refuses what it refuses. There is no stage a
 *     selection can reach that one application could not have reached alone,
 *     and no prerequisite a batch is allowed to skip.
 *   - A decline still carries a written reason, recorded against each
 *     candidate separately. That is what keeps it a decision somebody made
 *     rather than the automated rejection the brief rules out.
 *   - A reviewer without the ability to move stages gains nothing from the
 *     list screen, and the check is on the server, not on the button.
 */
class RecruitmentBulkActionsTest extends TestCase
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

    /** The panel is normally resolved by its middleware, which a Livewire test skips. */
    private function panel(\App\Models\User $user): void
    {
        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function at(ApplicationStage $stage, ?\App\Models\Vacancy $vacancy = null): JobApplication
    {
        return $this->forceStage(
            $this->makeApplication($vacancy ?? $this->makePublishedVacancy()),
            $stage,
        );
    }

    public function test_a_mixed_selection_each_moves_one_step(): void
    {
        $this->panel($this->makeRecruitmentAdmin());

        $fresh      = $this->at(ApplicationStage::Applied);
        $submitted  = $this->at(ApplicationStage::AssessmentSubmitted);
        $interviewed = $this->at(ApplicationStage::Interview);

        Livewire::test(ListJobApplications::class)
            ->callTableBulkAction('advance', [$fresh, $submitted, $interviewed]);

        // Each from where it actually was, not all to one stage.
        $this->assertSame(ApplicationStage::InitialReview, $fresh->fresh()->stage);
        $this->assertSame(ApplicationStage::Interview, $submitted->fresh()->stage);
        $this->assertSame(ApplicationStage::OfferChecks, $interviewed->fresh()->stage);
    }

    public function test_every_move_in_a_batch_is_recorded_as_its_own_event(): void
    {
        $admin = $this->makeRecruitmentAdmin();
        $this->panel($admin);

        $one = $this->at(ApplicationStage::Interview);
        $two = $this->at(ApplicationStage::Interview);

        Livewire::test(ListJobApplications::class)
            ->callTableBulkAction('advance', [$one, $two]);

        foreach ([$one, $two] as $application) {
            $event = $application->fresh()->stageEvents()->latest('id')->first();

            $this->assertNotNull($event);
            $this->assertSame(ApplicationStage::Interview, $event->from_stage);
            $this->assertSame(ApplicationStage::OfferChecks, $event->to_stage);
            $this->assertSame($admin->id, $event->actor_user_id);
        }
    }

    public function test_advance_refuses_the_assessment_stage_rather_than_faking_it(): void
    {
        $this->panel($this->makeRecruitmentAdmin());

        // The next stage after a first review is "invited to the assessment",
        // which cannot be true until an exercise has actually been sent. The
        // service refuses it; this is here so the bulk action cannot be
        // "fixed" later by routing around that.
        $reviewed = $this->at(ApplicationStage::InitialReview);

        Livewire::test(ListJobApplications::class)
            ->callTableBulkAction('advance', [$reviewed]);

        $this->assertSame(ApplicationStage::InitialReview, $reviewed->fresh()->stage);
        $this->assertSame(0, $reviewed->fresh()->assignments()->count());
    }

    public function test_one_that_cannot_move_does_not_stop_the_rest(): void
    {
        $this->panel($this->makeRecruitmentAdmin());

        $stuck  = $this->at(ApplicationStage::AssessmentInvited);
        $moving = $this->at(ApplicationStage::Interview);

        Livewire::test(ListJobApplications::class)
            ->callTableBulkAction('advance', [$stuck, $moving]);

        // It has submitted nothing, so the stage may not say it has.
        $this->assertSame(ApplicationStage::AssessmentInvited, $stuck->fresh()->stage);
        $this->assertSame(ApplicationStage::OfferChecks, $moving->fresh()->stage);
    }

    public function test_a_closed_application_is_left_alone(): void
    {
        $this->panel($this->makeRecruitmentAdmin());

        $declined = $this->at(ApplicationStage::Rejected);
        $hired    = $this->at(ApplicationStage::Hired);

        Livewire::test(ListJobApplications::class)
            ->callTableBulkAction('advance', [$declined, $hired]);

        $this->assertSame(ApplicationStage::Rejected, $declined->fresh()->stage);
        $this->assertSame(ApplicationStage::Hired, $hired->fresh()->stage);
    }

    public function test_the_checks_cannot_be_skipped_on_the_way_to_a_hire(): void
    {
        $this->panel($this->makeRecruitmentAdmin());

        // No accepted offer behind it, so "hired" would be a record nobody
        // could rely on — and a bulk action is exactly where that would slip
        // through unnoticed.
        $checks = $this->at(ApplicationStage::OfferChecks);

        Livewire::test(ListJobApplications::class)
            ->callTableBulkAction('advance', [$checks]);

        $this->assertSame(ApplicationStage::OfferChecks, $checks->fresh()->stage);
        $this->assertNull($checks->fresh()->hired_at);
    }

    public function test_declining_a_selection_records_the_reason_against_each_one(): void
    {
        $admin = $this->makeRecruitmentAdmin();
        $this->panel($admin);

        $one = $this->at(ApplicationStage::InitialReview);
        $two = $this->at(ApplicationStage::Interview);

        Livewire::test(ListJobApplications::class)
            ->callTableBulkAction('decline', [$one, $two], [
                'reason' => 'Looking for someone already licensed.',
                'notify' => false,
            ]);

        foreach ([$one, $two] as $application) {
            $application = $application->fresh();

            $this->assertSame(ApplicationStage::Rejected, $application->stage);
            $this->assertNotNull($application->rejected_at);
            $this->assertSame('Looking for someone already licensed.', $application->rejection_reason);

            $event = $application->stageEvents()->latest('id')->first();

            $this->assertSame(ApplicationStage::Rejected, $event->to_stage);
            $this->assertSame($admin->id, $event->actor_user_id);
            $this->assertSame('Looking for someone already licensed.', $event->reason);
        }
    }

    public function test_a_decline_with_no_reason_is_refused(): void
    {
        $this->panel($this->makeRecruitmentAdmin());

        $application = $this->at(ApplicationStage::InitialReview);

        Livewire::test(ListJobApplications::class)
            ->callTableBulkAction('decline', [$application], [
                'reason' => '',
                'notify' => false,
            ])
            ->assertHasTableBulkActionErrors(['reason']);

        $this->assertSame(ApplicationStage::InitialReview, $application->fresh()->stage);
    }

    public function test_declining_an_already_closed_application_changes_nothing(): void
    {
        $this->panel($this->makeRecruitmentAdmin());

        $withdrawn = $this->at(ApplicationStage::Withdrawn);

        Livewire::test(ListJobApplications::class)
            ->callTableBulkAction('decline', [$withdrawn], [
                'reason' => 'Tidying the list.',
                'notify' => false,
            ]);

        $withdrawn = $withdrawn->fresh();

        $this->assertSame(ApplicationStage::Withdrawn, $withdrawn->stage);
        $this->assertNull($withdrawn->rejected_at);
    }

    public function test_sending_the_exercise_to_a_shortlist_invites_each_of_them(): void
    {
        $this->panel($this->makeRecruitmentAdmin());

        $vacancy = $this->makePublishedVacancy();
        $one     = $this->at(ApplicationStage::InitialReview, $vacancy);
        $two     = $this->at(ApplicationStage::InitialReview, $vacancy);

        Livewire::test(ListJobApplications::class)
            ->callTableBulkAction('invite_assessment', [$one, $two], [
                'delay_working_days' => 0,
                'due_working_days'   => null,
            ]);

        foreach ([$one, $two] as $application) {
            $application = $application->fresh();

            $this->assertSame(ApplicationStage::AssessmentInvited, $application->stage);
            $this->assertSame(1, $application->assignments()->open()->count());
            $this->assertNotNull($application->currentAssignment()?->due_at);
        }
    }

    public function test_a_vacancy_with_no_exercise_is_named_rather_than_half_invited(): void
    {
        $this->panel($this->makeRecruitmentAdmin());

        $without = $this->makePublishedVacancy();
        $without->forceFill(['assessment_template_id' => null])->save();

        $orphan = $this->at(ApplicationStage::InitialReview, $without->refresh());
        $normal = $this->at(ApplicationStage::InitialReview);

        Livewire::test(ListJobApplications::class)
            ->callTableBulkAction('invite_assessment', [$orphan, $normal], [
                'delay_working_days' => 0,
                'due_working_days'   => null,
            ]);

        // Nothing created, nothing queued, and the stage still tells the truth.
        $this->assertSame(ApplicationStage::InitialReview, $orphan->fresh()->stage);
        $this->assertSame(0, $orphan->fresh()->assignments()->count());

        $this->assertSame(ApplicationStage::AssessmentInvited, $normal->fresh()->stage);
    }

    public function test_someone_past_that_point_is_not_sent_the_exercise_again(): void
    {
        $this->panel($this->makeRecruitmentAdmin());

        $interviewed = $this->at(ApplicationStage::Interview);

        Livewire::test(ListJobApplications::class)
            ->callTableBulkAction('invite_assessment', [$interviewed], [
                'delay_working_days' => 0,
                'due_working_days'   => null,
            ]);

        $this->assertSame(ApplicationStage::Interview, $interviewed->fresh()->stage);
        $this->assertSame(0, $interviewed->fresh()->assignments()->count());
    }

    public function test_marking_a_pile_as_being_reviewed_skips_the_ones_already_past_it(): void
    {
        $this->panel($this->makeRecruitmentAdmin());

        $fresh   = $this->at(ApplicationStage::Applied);
        $already = $this->at(ApplicationStage::Interview);

        // Intake writes the first event — applied, from nowhere — so the
        // count is what to watch, not whether there are any.
        $before = $already->stageEvents()->count();

        Livewire::test(ListJobApplications::class)
            ->callTableBulkAction('begin_review', [$fresh, $already]);

        $this->assertSame(ApplicationStage::InitialReview, $fresh->fresh()->stage);
        $this->assertSame(ApplicationStage::Interview, $already->fresh()->stage);

        // The one already past it is not credited with a second move.
        $this->assertSame($before, $already->fresh()->stageEvents()->count());
    }

    public function test_a_reviewer_who_cannot_move_stages_moves_nobody(): void
    {
        $reviewer = $this->makeRecruiter(RecruitmentPermission::REVIEWER);

        $vacancy = $this->makePublishedVacancy();
        $vacancy->reviewers()->attach($reviewer);

        // Assigned, so they can see the row and work on it. Scoring is what
        // they are for; deciding is not.
        $application = $this->at(ApplicationStage::Interview, $vacancy);

        $this->panel($reviewer);

        $component = Livewire::test(ListJobApplications::class);

        $component->assertTableBulkActionHidden('advance');
        $component->assertTableBulkActionHidden('decline');
        $component->assertTableBulkActionHidden('invite_assessment');
        $component->assertTableBulkActionHidden('begin_review');

        // And the button is not what is doing the work. Mounting and calling
        // it the way the browser does, past the hidden state, still moves
        // nobody: the panel refuses the mount and ApplicationStageService
        // would refuse the move behind it.
        $component
            ->call('mountTableBulkAction', 'advance', [$application->getKey()])
            ->call('callMountedTableBulkAction');

        $this->assertSame(ApplicationStage::Interview, $application->fresh()->stage);
    }

    public function test_a_coordinator_is_offered_all_four(): void
    {
        // The other half of the test above: a `visible()` closure that was
        // wrong in the other direction would hide these from everybody and
        // the suite would read as green.
        $coordinator = $this->makeRecruiter(RecruitmentPermission::COORDINATOR);

        $this->at(ApplicationStage::Interview);

        $this->panel($coordinator);

        $component = Livewire::test(ListJobApplications::class);

        $component->assertTableBulkActionVisible('begin_review');
        $component->assertTableBulkActionVisible('invite_assessment');
        $component->assertTableBulkActionVisible('advance');
        $component->assertTableBulkActionVisible('decline');
    }
}
