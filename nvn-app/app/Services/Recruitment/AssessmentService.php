<?php

namespace App\Services\Recruitment;

use App\Enums\ApplicationStage;
use App\Exceptions\StageTransitionException;
use App\Models\ApplicationStageEvent;
use App\Models\AssessmentAssignment;
use App\Models\JobApplication;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ExternalLink;
use App\Support\RecruitmentPermission;
use App\Support\RecruitmentTemplates;
use App\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inviting a candidate to an exercise, and taking it back in.
 *
 * The one idea that shapes this class: the exercise a candidate is doing must
 * never change under them. Templates are editable — that is the point of them —
 * so the moment an assignment is created the template is copied into
 * `template_snapshot` and the candidate's screen, the reviewer's rubric and the
 * candidate's own later re-reading all come from the copy. Somebody rewording a
 * task next week changes the next candidate's exercise and nobody else's.
 *
 * The second idea: the deadline is counted from when the invitation actually
 * goes out, not from when a reviewer pressed the button. An invitation held for
 * two working days gives the candidate their full three days from the day they
 * hear about it.
 */
class AssessmentService
{
    public function __construct(
        private RecruitmentOutbox $outbox,
        private ApplicationStageService $stages,
    ) {}

    /**
     * Invite a candidate, optionally after a delay in working days.
     *
     * The delay is a fixed number of working days chosen by an administrator —
     * never randomised, and never applied without the administrator having
     * approved the invitation in the first place. Default is immediate.
     *
     * @param  array{delay_working_days?: int, due_working_days?: ?int, notify?: bool}  $options
     *
     * @throws StageTransitionException
     */
    public function invite(JobApplication $application, ?User $actor, array $options = []): AssessmentAssignment
    {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::MOVE_STAGE)) {
            throw new StageTransitionException('You do not have permission to invite candidates to an assessment.');
        }

        if (! $application->isActive()) {
            throw new StageTransitionException('This application is closed, so no assessment can be sent.');
        }

        $vacancy  = $application->vacancy;
        $template = $vacancy?->template;

        if (! $template) {
            throw new StageTransitionException(
                'This vacancy has no assessment attached. Set one on the vacancy first.',
            );
        }

        $allowedDelays = (array) config('recruitment.invitation_delays', [0, 1, 2, 3]);
        $delay         = (int) ($options['delay_working_days'] ?? 0);
        $delay         = in_array($delay, array_map('intval', $allowedDelays), true) ? $delay : 0;

        $dueDays = (int) ($options['due_working_days'] ?? $application->vacancy->assessmentDueWorkingDays());
        $dueDays = max(1, min($dueDays, 30));

        return DB::transaction(function () use ($application, $actor, $template, $delay, $dueDays, $options) {
            // Applied cannot reach the assessment stage in one move — the only
            // thing that follows Applied is InitialReview — so somebody still
            // sitting in the pile is walked through it here rather than being
            // refused with a state-machine error. Sending an exercise *is* an
            // act of initial review: nobody selects a candidate for one
            // without having looked at them, and the alternative is an admin
            // pressing "mark as being reviewed" purely to satisfy the graph.
            //
            // Walked rather than skipped, so the history still shows the
            // pipeline being followed one stage at a time and the review is
            // recorded as having happened, which is the true account of it.
            if ($application->stage === ApplicationStage::Applied) {
                $this->stages->move($application, ApplicationStage::InitialReview, $actor, [
                    'skip_permission' => true,
                    'metadata'        => ['reason' => 'Reviewed by being selected for the exercise.'],
                ]);

                $application->refresh();
            }

            // Anything still open is replaced rather than left beside the new
            // one: two live assignments would give the candidate two deadlines.
            foreach ($application->assignments()->open()->get() as $existing) {
                $this->cancelAssignment($existing, $actor, 'Replaced by a new invitation.');
            }

            $dispatchAt = $delay === 0
                ? CarbonImmutable::now()
                : WorkingCalendar::addWorkingDays(CarbonImmutable::now(), $delay, '09:00');

            $dueAt = WorkingCalendar::addWorkingDays(
                $dispatchAt,
                $dueDays,
                (string) config('recruitment.assessment.due_at_time', '17:00'),
            );

            $assignment = $application->assignments()->create([
                'assessment_template_id' => $template->id,
                'template_version'       => $template->version,
                'template_snapshot'      => $template->snapshot(),
                'status'                 => AssessmentAssignment::INVITED,
                'invited_at'             => $dispatchAt,
                'due_at'                 => $dueAt,
                'original_due_at'        => $dueAt,
                'extension_count'        => 0,
            ]);

            $this->stages->move($application, ApplicationStage::AssessmentInvited, $actor, [
                'skip_permission' => true,
                'metadata'        => ['assignment_id' => $assignment->id, 'delay_working_days' => $delay],
            ]);

            if ($options['notify'] ?? true) {
                $this->queueInvitation($assignment->fresh(), $dispatchAt);
            }

            AuditLogger::record('recruitment_assessment.invited', 'assessment_assignment', $assignment->id, [
                'application'        => $application->reference,
                'template_version'   => $template->version,
                'delay_working_days' => $delay,
                'due_at'             => $dueAt->toIso8601String(),
            ], $actor?->id);

            return $assignment->fresh();
        });
    }

    /** Queue the invitation and the one reminder that follows it. */
    public function queueInvitation(AssessmentAssignment $assignment, ?CarbonImmutable $dispatchAt = null): void
    {
        $application = $assignment->application;
        $dispatchAt ??= CarbonImmutable::parse($assignment->invited_at);

        $this->outbox->queue(RecruitmentTemplates::ASSESSMENT_SENT, $application, [
            'event_key'    => $this->outbox->eventKey(
                RecruitmentTemplates::ASSESSMENT_SENT,
                $application,
                (string) $assignment->id,
            ),
            'available_at' => $dispatchAt,
            'action_url'   => route('candidate.login'),
            'data'         => $this->outbox->baseData($application) + [
                'deadline' => $this->outbox->deadlineFor($application, $assignment->due_at),
                'effort'   => $assignment->effortLine(),
            ],
            'guard' => ['assignment' => $assignment->id],
        ]);

        $this->queueReminder($assignment);
    }

    /**
     * One reminder, a working day before the deadline.
     *
     * Keyed on the deadline itself, so extending a deadline queues a fresh
     * reminder rather than reusing the one that was timed for the old date —
     * and re-extending twice to the same date does not produce two.
     */
    public function queueReminder(AssessmentAssignment $assignment): void
    {
        if (! (bool) config('recruitment.assessment.reminders.enabled', true)) {
            return;
        }

        $dueAt = $assignment->due_at;

        if (! $dueAt) {
            return;
        }

        $before = max(0, (int) config('recruitment.assessment.reminders.working_days_before', 1));
        $sendAt = WorkingCalendar::subWorkingDays(CarbonImmutable::parse($dueAt), $before, '09:00');

        // A deadline that is already inside the reminder window gets no
        // reminder: one that would arrive after the deadline is worse than none.
        if ($sendAt->isPast() || $sendAt->greaterThanOrEqualTo($dueAt)) {
            return;
        }

        $application = $assignment->application;

        $this->outbox->queue(RecruitmentTemplates::ASSESSMENT_NUDGE, $application, [
            'event_key'    => $this->outbox->eventKey(
                RecruitmentTemplates::ASSESSMENT_NUDGE,
                $application,
                (string) $assignment->id,
                (string) CarbonImmutable::parse($dueAt)->timestamp,
            ),
            'available_at' => $sendAt,
            'action_url'   => route('candidate.login'),
            'data'         => $this->outbox->baseData($application) + [
                'deadline' => $this->outbox->deadlineFor($application, $dueAt),
                'effort'   => $assignment->effortLine(),
            ],
            'guard' => ['assignment' => $assignment->id, 'before_due' => true],
        ]);
    }

    /**
     * Give a candidate more time. Always recorded, always visible.
     *
     * @throws StageTransitionException
     */
    public function extend(AssessmentAssignment $assignment, ?User $actor, int $days, string $reason): AssessmentAssignment
    {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::MOVE_STAGE)) {
            throw new StageTransitionException('You do not have permission to change a deadline.');
        }

        $max = (int) config('recruitment.assessment.max_extension_days', 10);
        $days = max(1, min($days, $max));

        if ($assignment->isSubmitted()) {
            throw new StageTransitionException('This assessment has already been submitted.');
        }

        return DB::transaction(function () use ($assignment, $actor, $days, $reason) {
            $assignment = $this->locked($assignment);

            $from = CarbonImmutable::parse($assignment->due_at ?? now());
            // Counted from today when the deadline has already slipped past,
            // so an extension always lands in the future.
            $base = $from->isPast() ? CarbonImmutable::now() : $from;

            $newDue = WorkingCalendar::addWorkingDays(
                $base,
                $days,
                (string) config('recruitment.assessment.due_at_time', '17:00'),
            );

            $assignment->forceFill([
                'due_at'          => $newDue,
                'extension_count' => $assignment->extension_count + 1,
                'extended_at'     => now(),
                'extended_by'     => $actor?->id,
                'status'          => $assignment->status === AssessmentAssignment::OVERDUE
                    ? AssessmentAssignment::INVITED
                    : $assignment->status,
            ])->save();

            // The old reminder was timed for the old deadline, so it goes.
            $this->outbox->cancelPending(
                $assignment->application,
                [RecruitmentTemplates::ASSESSMENT_NUDGE],
                'The deadline was extended.',
            );

            $this->queueReminder($assignment->fresh());

            AuditLogger::record('recruitment_assessment.extended', 'assessment_assignment', $assignment->id, [
                'application' => $assignment->application->reference,
                'days'        => $days,
                'due_at'      => $newDue->toIso8601String(),
                'reason'      => Str::limit($reason, 160),
            ], $actor?->id);

            return $assignment->fresh();
        });
    }

    /**
     * Let a candidate edit a finalised submission again.
     *
     * Only a reviewer can do this, and the earlier answers are kept: the
     * revision number on each response is what makes "they changed it after we
     * asked" visible rather than lost.
     *
     * @throws StageTransitionException
     */
    public function reopen(AssessmentAssignment $assignment, ?User $actor, string $reason, ?int $extraDays = null): AssessmentAssignment
    {
        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::MOVE_STAGE)) {
            throw new StageTransitionException('You do not have permission to reopen an assessment.');
        }

        if (trim($reason) === '') {
            throw new StageTransitionException('Reopening an assessment needs a reason recorded.');
        }

        return DB::transaction(function () use ($assignment, $actor, $reason, $extraDays) {
            $assignment = $this->locked($assignment);

            $due = $extraDays
                ? WorkingCalendar::addWorkingDays(
                    CarbonImmutable::now(),
                    max(1, $extraDays),
                    (string) config('recruitment.assessment.due_at_time', '17:00'),
                )
                : $assignment->due_at;

            $assignment->forceFill([
                'status'        => AssessmentAssignment::REOPENED,
                'submitted_at'  => null,
                'reopened_at'   => now(),
                'reopened_by'   => $actor?->id,
                'reopen_reason' => Str::limit($reason, 480),
                'due_at'        => $due,
            ])->save();

            AuditLogger::record('recruitment_assessment.reopened', 'assessment_assignment', $assignment->id, [
                'application' => $assignment->application->reference,
                'reason'      => Str::limit($reason, 160),
            ], $actor?->id);

            return $assignment->fresh();
        });
    }

    /** Called off — the candidate left, or a newer invitation replaced it. */
    public function cancelAssignment(AssessmentAssignment $assignment, ?User $actor, string $reason): void
    {
        $assignment->forceFill([
            'status'       => AssessmentAssignment::CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        $this->outbox->cancelPending(
            $assignment->application,
            [RecruitmentTemplates::ASSESSMENT_SENT, RecruitmentTemplates::ASSESSMENT_NUDGE],
            $reason,
        );

        AuditLogger::record('recruitment_assessment.cancelled', 'assessment_assignment', $assignment->id, [
            'application' => $assignment->application->reference,
            'reason'      => Str::limit($reason, 160),
        ], $actor?->id);
    }

    /* ---- the candidate's side ------------------------------------------- */

    /**
     * Save a draft. Never a final answer, and never after submission.
     *
     * @param  array<string, string>  $answers  keyed by task key
     */
    public function saveDraft(AssessmentAssignment $assignment, array $answers): void
    {
        if (! $assignment->acceptsChanges()) {
            throw new StageTransitionException('This assessment is no longer open for changes.');
        }

        DB::transaction(function () use ($assignment, $answers) {
            foreach ($assignment->tasks() as $task) {
                $key = (string) ($task['key'] ?? '');

                if ($key === '' || ! array_key_exists($key, $answers)) {
                    continue;
                }

                $this->writeResponse($assignment, $task, (string) $answers[$key], false);
            }
        });
    }

    /**
     * Which required tasks are still empty.
     *
     * @param  array<string, string>  $answers
     * @return list<string>
     */
    public function missingRequired(AssessmentAssignment $assignment, array $answers): array
    {
        $missing = [];

        foreach ($assignment->tasks() as $task) {
            $key = (string) ($task['key'] ?? '');

            if ($key === '' || ! (bool) ($task['required'] ?? true)) {
                continue;
            }

            if (trim((string) ($answers[$key] ?? '')) === '') {
                $missing[] = (string) ($task['label'] ?? $key);
            }
        }

        return $missing;
    }

    /**
     * Submit. Finalises every answer and stamps the assignment.
     *
     * A late submission is accepted and marked late rather than refused: what
     * to do about a late exercise belongs to the person reading it, not to a
     * clock. An empty required answer IS refused, because that is almost
     * always a candidate who believed they had saved.
     *
     * @param  array<string, string>  $answers
     *
     * @throws StageTransitionException
     */
    public function submit(
        AssessmentAssignment $assignment,
        array $answers,
        ?string $introVideoUrl = null,
        ?string $introAlternative = null,
    ): AssessmentAssignment {
        if ($closed = $assignment->closedReason()) {
            throw new StageTransitionException($closed);
        }

        $missing = $this->missingRequired($assignment, $answers);

        if ($missing !== []) {
            throw new StageTransitionException(
                'Please answer: ' . implode('; ', array_slice($missing, 0, 4))
                . (count($missing) > 4 ? ', and the others.' : '.'),
            );
        }

        $video = null;

        if (trim((string) $introVideoUrl) !== '') {
            $video = ExternalLink::normalise((string) $introVideoUrl);

            if (! $video) {
                throw new StageTransitionException('That video link does not look like a web address.');
            }
        }

        return DB::transaction(function () use ($assignment, $answers, $video, $introAlternative) {
            foreach ($assignment->tasks() as $task) {
                $key = (string) ($task['key'] ?? '');

                if ($key === '') {
                    continue;
                }

                $this->writeResponse($assignment, $task, (string) ($answers[$key] ?? ''), true);
            }

            $late = $assignment->due_at ? now()->greaterThan($assignment->due_at) : false;

            $assignment->forceFill([
                'status'            => AssessmentAssignment::SUBMITTED,
                'submitted_at'      => now(),
                'intro_video_url'   => $video,
                'intro_alternative' => $introAlternative ? Str::limit($introAlternative, 4000, '') : null,
            ])->save();

            $application = $assignment->application;

            $this->stages->move($application, ApplicationStage::AssessmentSubmitted, null, [
                'actor_type'      => ApplicationStageEvent::ACTOR_CANDIDATE,
                'skip_permission' => true,
                'metadata'        => ['assignment_id' => $assignment->id, 'late' => $late],
            ]);

            $this->outbox->cancelPending(
                $application,
                [RecruitmentTemplates::ASSESSMENT_NUDGE],
                'The candidate submitted.',
            );

            $this->outbox->queue(RecruitmentTemplates::ASSESSMENT_GOT, $application, [
                'event_key'  => $this->outbox->eventKey(
                    RecruitmentTemplates::ASSESSMENT_GOT,
                    $application,
                    (string) $assignment->id,
                ),
                'action_url' => route('candidate.login'),
                'data'       => $this->outbox->baseData($application),
            ]);

            AuditLogger::record('recruitment_assessment.submitted', 'assessment_assignment', $assignment->id, [
                'application' => $application->reference,
                'late'        => $late,
            ]);

            return $assignment->fresh();
        });
    }

    /**
     * Write one answer, keeping what was there before.
     *
     * A draft overwrites the current draft. Finalising bumps the revision, so
     * a reopened-and-resubmitted assessment keeps both versions and a reviewer
     * can see that it changed.
     *
     * @param  array<string, mixed>  $task
     */
    private function writeResponse(AssessmentAssignment $assignment, array $task, string $answer, bool $final): void
    {
        $key      = (string) $task['key'];
        $existing = $assignment->responses()->where('task_key', $key)->orderByDesc('revision')->first();

        $revision = $existing
            ? ($final && $existing->is_final ? $existing->revision + 1 : $existing->revision)
            : 1;

        $attributes = [
            'task_label' => Str::limit((string) ($task['label'] ?? $key), 190, ''),
            'task_type'  => (string) ($task['type'] ?? 'long_text'),
            'answer'     => Str::limit($answer, 20000, ''),
            'is_final'   => $final,
        ];

        if ($existing && $revision === $existing->revision) {
            $existing->forceFill($attributes)->save();

            return;
        }

        $assignment->responses()->create($attributes + [
            'task_key' => $key,
            'revision' => $revision,
        ]);
    }

    /**
     * Mark open assessments whose deadline has passed.
     *
     * Overdue is a substate of the assignment, not a stage: the candidate is
     * still at "Assessment invited" and may still submit. Nothing is rejected
     * here — the column exists so an administrator can see who to chase.
     */
    /**
     * The row as the database has it, held for the rest of the transaction.
     *
     * Both extend() and reopen() work by forceFilling an assignment and
     * saving it, and save() writes only what it believes has changed. Handed
     * a stale instance — one loaded before the candidate submitted, or before
     * the sweep flagged it overdue — "submitted_at => null" and
     * "status => INVITED" look like no change at all and are quietly dropped,
     * leaving a reopened assignment still marked submitted and an extended one
     * still marked overdue. Re-reading under a lock removes the question, and
     * stops two reviewers moving the same deadline at once.
     */
    private function locked(AssessmentAssignment $assignment): AssessmentAssignment
    {
        return AssessmentAssignment::query()
            ->whereKey($assignment->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function markOverdue(): int
    {
        $marked = 0;

        AssessmentAssignment::query()
            ->whereIn('status', [AssessmentAssignment::INVITED, AssessmentAssignment::REOPENED])
            ->whereNull('submitted_at')
            ->whereNull('cancelled_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->orderBy('id')
            ->chunkById(200, function ($assignments) use (&$marked) {
                foreach ($assignments as $assignment) {
                    $assignment->forceFill(['status' => AssessmentAssignment::OVERDUE])->save();
                    $marked++;
                }
            });

        return $marked;
    }
}
