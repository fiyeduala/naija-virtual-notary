<?php

namespace App\Services\Recruitment;

use App\Enums\ApplicationStage;
use App\Exceptions\StageTransitionException;
use App\Models\ApplicationStageEvent;
use App\Models\JobApplication;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\RecruitmentPermission;
use App\Support\RecruitmentTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only thing allowed to change an application's stage.
 *
 * Nothing in this module writes `stage` directly. Every change comes through
 * here so that four things are guaranteed together, which is the part that
 * cannot be done at the call sites:
 *
 *   1. The move is one the state machine permits, checked on the server. A
 *      reviewer cannot reach a stage by crafting a request, and a bulk action
 *      cannot skip a step because the list screen offered it.
 *   2. The prerequisites hold. "Assessment submitted" requires a submission
 *      that exists — a reviewer who ticks it off without one is recording
 *      something false, and the pipeline numbers stop meaning anything.
 *   3. The row is locked for the duration, so two reviewers acting at the same
 *      moment produce one move and one history entry, not two half-applied
 *      ones.
 *   4. Queued emails that the move has made wrong are called off in the same
 *      transaction as the move itself.
 *
 * What this service deliberately does NOT do: decide. There is no scoring
 * threshold that advances anybody, no rule that rejects a late submission, no
 * ranking. Every call here is a person having made up their mind.
 */
class ApplicationStageService
{
    public function __construct(private RecruitmentOutbox $outbox) {}

    /**
     * What may follow what.
     *
     * Rejection and withdrawal are reachable from every live stage and are
     * therefore not repeated in each row; see allowedFrom().
     *
     * @return array<string, list<string>>
     */
    public static function graph(): array
    {
        return [
            ApplicationStage::Applied->value => [
                ApplicationStage::InitialReview->value,
            ],
            ApplicationStage::InitialReview->value => [
                ApplicationStage::AssessmentInvited->value,
                ApplicationStage::Interview->value,
            ],
            ApplicationStage::AssessmentInvited->value => [
                ApplicationStage::AssessmentSubmitted->value,
                ApplicationStage::Interview->value,
                ApplicationStage::InitialReview->value,
            ],
            ApplicationStage::AssessmentSubmitted->value => [
                ApplicationStage::Interview->value,
                ApplicationStage::AssessmentInvited->value,
            ],
            ApplicationStage::Interview->value => [
                ApplicationStage::OfferChecks->value,
                ApplicationStage::Interview->value,
            ],
            ApplicationStage::OfferChecks->value => [
                ApplicationStage::Hired->value,
                ApplicationStage::Interview->value,
            ],
            // A hire is the end of the pipeline. Undoing one is an employment
            // matter, not a button.
            ApplicationStage::Hired->value => [],
            // Reopening is allowed, with a reason, and lands back in review
            // rather than wherever the candidate was.
            ApplicationStage::Rejected->value => [
                ApplicationStage::InitialReview->value,
            ],
            ApplicationStage::Withdrawn->value => [
                ApplicationStage::InitialReview->value,
            ],
        ];
    }

    /** @return list<ApplicationStage> */
    public function allowedFrom(ApplicationStage $stage): array
    {
        $values = static::graph()[$stage->value] ?? [];

        if ($stage->isActive()) {
            $values = [...$values, ApplicationStage::Rejected->value, ApplicationStage::Withdrawn->value];
        }

        return array_map(fn (string $v) => ApplicationStage::from($v), array_values(array_unique($values)));
    }

    public function canMove(JobApplication $application, ApplicationStage $to): bool
    {
        return in_array($to, $this->allowedFrom($application->stage), true);
    }

    /**
     * Does this move need the mover to say why?
     *
     * Going backwards, reopening a closed application, or jumping over a stage
     * all do. Walking the pipeline forward one step does not — the stage change
     * is its own explanation.
     */
    public function requiresReason(ApplicationStage $from, ApplicationStage $to): bool
    {
        if ($to === ApplicationStage::Rejected || ! $from->isActive()) {
            return true;
        }

        $fromAt = $from->position();
        $toAt   = $to->position();

        if ($fromAt === null || $toAt === null) {
            return false;
        }

        return $toAt <= $fromAt || $toAt > $fromAt + 1;
    }

    /**
     * Move an application. The one entry point.
     *
     * @param  array{reason?: ?string, actor_type?: string, metadata?: array, skip_permission?: bool}  $options
     *
     * @throws StageTransitionException
     */
    public function move(
        JobApplication $application,
        ApplicationStage $to,
        ?User $actor,
        array $options = [],
    ): JobApplication {
        $reason    = trim((string) ($options['reason'] ?? '')) ?: null;
        $actorType = (string) ($options['actor_type'] ?? ApplicationStageEvent::ACTOR_STAFF);

        if ($actorType === ApplicationStageEvent::ACTOR_STAFF && ! ($options['skip_permission'] ?? false)) {
            if (! RecruitmentPermission::allows($actor, RecruitmentPermission::MOVE_STAGE)) {
                throw new StageTransitionException('You do not have permission to move applications between stages.');
            }

            if (! RecruitmentPermission::canReview($actor, $application)) {
                throw new StageTransitionException('This application is not assigned to you.');
            }
        }

        return DB::transaction(function () use ($application, $to, $actor, $reason, $actorType, $options) {
            /** @var JobApplication $fresh */
            $fresh = JobApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $from  = $fresh->stage;

            if ($from === $to && $to !== ApplicationStage::Interview) {
                // Not an error: two reviewers pressed the same button. The
                // first one's move stands and the second is a no-op.
                return $fresh;
            }

            if (! $this->canMove($fresh, $to)) {
                throw new StageTransitionException(sprintf(
                    '%s cannot move straight to %s.',
                    $from->label(),
                    $to->label(),
                ));
            }

            if ($this->requiresReason($from, $to) && $reason === null) {
                throw new StageTransitionException(sprintf(
                    'Moving from %s to %s needs a reason recorded.',
                    $from->label(),
                    $to->label(),
                ));
            }

            $this->assertPrerequisites($fresh, $to);

            // Every terminal mark is set or cleared on every move, never left
            // behind: a reopened application that still carried rejected_at
            // would read as rejected and in review at the same time, and the
            // outbox would refuse to send it anything.
            $fresh->forceFill([
                'stage'             => $to->value,
                'stage_changed_at'  => now(),
                'rejected_at'       => $to === ApplicationStage::Rejected ? now() : null,
                'rejection_reason'  => $to === ApplicationStage::Rejected ? $reason : null,
                'withdrawn_at'      => $to === ApplicationStage::Withdrawn ? now() : null,
                'withdrawal_reason' => $to === ApplicationStage::Withdrawn ? $reason : null,
                'hired_at'          => $to === ApplicationStage::Hired ? now() : null,
                'hired_by'          => $to === ApplicationStage::Hired ? $actor?->id : null,
            ])->save();

            $event = $fresh->stageEvents()->create([
                'from_stage'     => $from->value,
                'to_stage'       => $to->value,
                'actor_type'     => $actorType,
                'actor_user_id'  => $actorType === ApplicationStageEvent::ACTOR_STAFF ? $actor?->id : null,
                'reason'         => $reason,
                'metadata'       => $options['metadata'] ?? null,
            ]);

            AuditLogger::record(
                'recruitment_application.stage_changed',
                'job_application',
                $fresh->id,
                [
                    'reference' => $fresh->reference,
                    'from'      => $from->value,
                    'to'        => $to->value,
                    'actor'     => $actorType,
                    // The reason is a reviewer's words about a person. Only
                    // whether there was one is kept in the audit chain.
                    'reasoned'  => $reason !== null,
                    'event_id'  => $event->id,
                ],
                $actorType === ApplicationStageEvent::ACTOR_STAFF ? $actor?->id : null,
            );

            $this->invalidateMessages($fresh, $to);

            return $fresh;
        });
    }

    /**
     * Facts that must already be true before a stage can claim them.
     *
     * @throws StageTransitionException
     */
    private function assertPrerequisites(JobApplication $application, ApplicationStage $to): void
    {
        if ($to === ApplicationStage::AssessmentInvited) {
            $open = $application->assignments()->open()->exists();

            if (! $open) {
                throw new StageTransitionException(
                    'Send the assessment first — there is no open assignment for this candidate. '
                    . 'Use "Invite to assessment", which creates one and moves the stage with it.',
                );
            }
        }

        if ($to === ApplicationStage::AssessmentSubmitted) {
            $submitted = $application->assignments()->whereNotNull('submitted_at')->exists();

            if (! $submitted) {
                throw new StageTransitionException(
                    'This candidate has not submitted an assessment, so the stage cannot say they have.',
                );
            }
        }

        if ($to === ApplicationStage::Hired) {
            $offer = $application->offer;

            if (! $offer || $offer->status !== \App\Models\Offer::ACCEPTED) {
                throw new StageTransitionException(
                    'Mark the offer accepted before hiring. A hire with no accepted offer behind it '
                    . 'is a record nobody can rely on.',
                );
            }

            $outstanding = $application->onboardingItems()
                ->where('is_required', true)
                ->whereNotIn('status', [
                    \App\Models\OnboardingItem::VERIFIED,
                    \App\Models\OnboardingItem::WAIVED,
                ])
                ->count();

            if ($outstanding > 0) {
                throw new StageTransitionException(sprintf(
                    '%d required checklist %s still outstanding. Verify or waive each one first.',
                    $outstanding,
                    $outstanding === 1 ? 'item is' : 'items are',
                ));
            }
        }
    }

    /**
     * Call off queued emails the move has made wrong.
     *
     * A withdrawal or a rejection cancels everything still waiting. Moving on
     * from the assessment stages cancels invitations and reminders, which is
     * what stops a candidate already at interview being nagged about an
     * exercise they finished a week ago.
     */
    private function invalidateMessages(JobApplication $application, ApplicationStage $to): void
    {
        if (in_array($to, [ApplicationStage::Rejected, ApplicationStage::Withdrawn], true)) {
            $this->outbox->cancelPending(
                $application,
                [],
                $to === ApplicationStage::Rejected
                    ? 'The application was rejected.'
                    : 'The candidate withdrew.',
            );

            return;
        }

        if (in_array($to, [ApplicationStage::Interview, ApplicationStage::OfferChecks, ApplicationStage::Hired], true)) {
            $this->outbox->cancelPending(
                $application,
                [RecruitmentTemplates::ASSESSMENT_SENT, RecruitmentTemplates::ASSESSMENT_NUDGE],
                'The candidate has moved past the assessment stage.',
            );
        }
    }

    /* ---- the named moves, which is what call sites actually use --------- */

    /** A reviewer has read the application and opened it. */
    public function beginReview(JobApplication $application, ?User $actor): JobApplication
    {
        return $this->move($application, ApplicationStage::InitialReview, $actor);
    }

    /** Turned down, by a person, with a reason recorded internally. */
    public function reject(JobApplication $application, ?User $actor, string $reason, bool $notify = true): JobApplication
    {
        $application = $this->move($application, ApplicationStage::Rejected, $actor, [
            'reason' => $reason ?: 'No reason recorded.',
        ]);

        if ($notify) {
            $this->outbox->queue(RecruitmentTemplates::REJECTED, $application, [
                'event_key' => $this->outbox->eventKey(
                    RecruitmentTemplates::REJECTED,
                    $application,
                    (string) $application->rejected_at?->timestamp,
                ),
                // The internal reason is never in the email. What a reviewer
                // wrote for colleagues is not what a candidate should read.
                'data' => $this->outbox->baseData($application),
            ]);
        }

        return $application;
    }

    /** The candidate's own doing, from their own screen. */
    public function withdraw(JobApplication $application, ?string $reason = null): JobApplication
    {
        $application = $this->move($application, ApplicationStage::Withdrawn, null, [
            'reason'     => $reason ? Str::limit($reason, 480) : 'Withdrawn by the candidate.',
            'actor_type' => ApplicationStageEvent::ACTOR_CANDIDATE,
        ]);

        $this->outbox->queue(RecruitmentTemplates::WITHDRAWN, $application, [
            'event_key' => $this->outbox->eventKey(
                RecruitmentTemplates::WITHDRAWN,
                $application,
                (string) $application->withdrawn_at?->timestamp,
            ),
            'data' => $this->outbox->baseData($application),
        ]);

        return $application;
    }

    /** Hired. Always a person, always after the checklist is clear. */
    public function hire(JobApplication $application, ?User $actor): JobApplication
    {
        // Worth saying out loud: nothing here creates a user account, grants a
        // role or touches users.recruitment_role. Hiring somebody on this
        // screen does not give them a login, by design.
        return $this->move($application, ApplicationStage::Hired, $actor, [
            'reason' => 'Checklist complete and offer accepted.',
        ]);
    }
}
