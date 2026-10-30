<?php

namespace App\Console\Commands;

use App\Models\AssessmentAssignment;
use App\Models\RecruitmentMessage;
use App\Models\User;
use App\Services\Recruitment\AssessmentService;
use App\Services\Recruitment\RecruitmentOutbox;
use App\Support\AuditLogger;
use App\Support\RecruitmentPermission;
use App\Support\RecruitmentTemplates;
use App\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * One deadline reminder to everybody who has not submitted yet.
 *
 * The automatic reminder fires one working day before a deadline, and most of
 * a cohort gets it that way without anybody running anything. This is for the
 * cases that route misses, which are all the same shape: a deadline moved
 * inside the reminder window, so queueReminder() declined to schedule a nudge
 * that would have arrived after the thing it was reminding about; a reminder
 * cancelled by an extension and never replaced; a reminder that went out
 * before the wording it needed existed.
 *
 * Four decisions, each with its reason:
 *
 *   - It sends the ordinary ASSESSMENT_NUDGE rather than a template of its
 *     own. The wording a candidate needs here — this is the date, here is the
 *     link, do not sign out — is what every reminder should say, not something
 *     peculiar to one cohort, and templates render at send time, so improving
 *     the one template also improves every reminder already sitting queued.
 *   - A candidate who already has a reminder waiting is left alone. The point
 *     of running this is the people a reminder never reached, and a second
 *     copy to everybody else is volume the host's mail queue has already been
 *     suspended once for carrying.
 *   - It carries route('candidate.login') and no token. That page is the same
 *     address every time and cannot expire, which is the only kind of link
 *     that is still true after mail has sat in a queue for an hour.
 *   - --move is opt-in. The email quotes each candidate's own deadline, so it
 *     is honest whether or not anybody was moved; putting twelve hundred
 *     deadlines on one date is a decision with an audit row per candidate, and
 *     it should take a flag and a named administrator to make it.
 *
 * Running it twice is safe: the event key is stable per assignment and
 * deadline, so the second run finds every message already written.
 */
class AssessmentDeadline extends Command
{
    protected $signature = 'nvn:assessment-deadline
                            {--deadline= : The date everybody should be on, as YYYY-MM-DD}
                            {--within=14 : Only assignments invited within this many days}
                            {--move : Put any deadline earlier than that date onto it}
                            {--actor= : Email of the administrator this is recorded against}
                            {--apply : Write the change; without this the command only reports}';

    protected $description = 'Remind everybody who has not submitted their exercise, and tell them not to sign out';

    public function __construct(
        private AssessmentService $assessments,
        private RecruitmentOutbox $outbox,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('recruitment.enabled')) {
            $this->line('Recruitment is switched off; nothing to do.');

            return self::SUCCESS;
        }

        $within = max(1, (int) $this->option('within'));
        $apply  = (bool) $this->option('apply');
        $move   = (bool) $this->option('move');

        $deadline = $this->resolveDeadline();

        if (! $deadline) {
            return self::FAILURE;
        }

        $actor = null;

        if ($apply) {
            $actor = $this->resolveActor($move);

            if (! $actor) {
                return self::FAILURE;
            }
        }

        $assignments = $this->inScope($within);

        if ($assignments->isEmpty()) {
            $this->line('Nobody has an unsubmitted exercise from the last ' . $within . ' ' . str('day')->plural($within) . '.');

            return self::SUCCESS;
        }

        $this->report($assignments, $deadline, $within);

        if (! $apply) {
            $this->newLine();
            $this->line('Nothing was changed. Re-run with --apply --actor=<admin email>'
                . ($move ? ' --move' : '') . ' to queue the reminders.');

            return self::SUCCESS;
        }

        $moved   = 0;
        $queued  = 0;
        $kept    = 0;
        $skipped = 0;

        foreach ($assignments as $assignment) {
            if ($move && $this->isEarly($assignment, $deadline)) {
                try {
                    $assignment = $this->assessments->extend(
                        $assignment,
                        $actor,
                        1,
                        'Deadline reminder: the whole cohort was put on one date.',
                        $deadline,
                    );
                } catch (\Throwable $e) {
                    // One assignment that will not move must not stop the
                    // rest, and the reference is enough to find it after.
                    $this->warn($assignment->application->reference . ': ' . $e->getMessage());
                    $skipped++;

                    continue;
                }

                $moved++;
            }

            // Read after the move, never before: extend() cancels the reminder
            // that was timed for the old deadline, and will not have replaced
            // it when the new one is already inside the reminder window —
            // which is exactly the gap this command exists to fill.
            if ($this->hasReminderWaiting($assignment)) {
                $kept++;

                continue;
            }

            $this->writeTo($assignment);
            $queued++;
        }

        AuditLogger::record('recruitment_assessment.deadline_reminded', null, null, [
            'queued'      => $queued,
            'kept'        => $kept,
            'moved'       => $moved,
            'skipped'     => $skipped,
            'deadline'    => $deadline->toIso8601String(),
            'within_days' => $within,
            'moved_dates' => $move,
        ], $actor->id);

        $this->newLine();

        if ($move) {
            $this->info(sprintf(
                '%d %s moved to %s.',
                $moved,
                str('deadline')->plural($moved),
                $deadline->format('D j M, g:ia'),
            ));
        }

        $this->info(sprintf(
            '%d %s queued. %d already had one waiting. %d skipped.',
            $queued,
            str('reminder')->plural($queued),
            $kept,
            $skipped,
        ));

        $this->line('The outbox paces them; nothing is sent by this command.');

        return self::SUCCESS;
    }

    /**
     * Everybody who could still submit.
     *
     * Reopened assignments are deliberately absent, the same way they are from
     * the follow-up: a reviewer handed that submission back with a deadline
     * they chose and a reason attached, and a blanket reminder about a cohort
     * deadline is not the email that fits it.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AssessmentAssignment>
     */
    private function inScope(int $within): \Illuminate\Database\Eloquent\Collection
    {
        return AssessmentAssignment::query()
            ->whereIn('status', [AssessmentAssignment::INVITED, AssessmentAssignment::OVERDUE])
            ->whereNull('cancelled_at')
            ->whereNull('submitted_at')
            ->whereNotNull('invited_at')
            ->where('invited_at', '>=', now()->subDays($within))
            ->whereHas('application', fn ($query) => $query
                ->active()
                ->whereHas('candidate', fn ($candidate) => $candidate
                    ->whereNull('anonymised_at')
                    ->whereNotNull('email')))
            ->with(['application.candidate', 'application.vacancy'])
            ->orderBy('id')
            ->get();
    }

    /**
     * What is actually there, before anybody decides to change it.
     *
     * The deadlines are listed by date because the email quotes each
     * candidate's own: a cohort sitting on three different dates is three
     * different emails going out, and that is worth seeing before --move is
     * either used or left off.
     *
     * @param  \Illuminate\Support\Collection<int, AssessmentAssignment>  $assignments
     */
    private function report($assignments, CarbonImmutable $deadline, int $within): void
    {
        $waiting = $assignments->filter(fn ($assignment) => $this->hasReminderWaiting($assignment));
        $early   = $assignments->filter(fn ($assignment) => $this->isEarly($assignment, $deadline));

        $this->line(sprintf(
            '%d unsubmitted %s from the last %d %s.',
            $assignments->count(),
            str('exercise')->plural($assignments->count()),
            $within,
            str('day')->plural($within),
        ));

        $this->line(sprintf(
            '  %d already have a reminder waiting in the outbox; %d do not.',
            $waiting->count(),
            $assignments->count() - $waiting->count(),
        ));

        $this->newLine();
        $this->line('Deadlines they would be told:');

        $byDate = $assignments
            ->groupBy(fn ($assignment) => $assignment->due_at
                ? CarbonImmutable::parse($assignment->due_at)->format('Y-m-d')
                : 'none')
            ->sortKeys();

        foreach ($byDate as $date => $group) {
            $this->line(sprintf('  %-12s %d', $date, $group->count()));
        }

        if ($early->isNotEmpty()) {
            $this->newLine();
            $this->warn(sprintf(
                '%d %s earlier than %s. Without --move they are reminded of their own date, not that one.',
                $early->count(),
                str('deadline')->plural($early->count()),
                $deadline->format('j M'),
            ));
        }
    }

    private function isEarly(AssessmentAssignment $assignment, CarbonImmutable $deadline): bool
    {
        return $assignment->due_at === null
            || CarbonImmutable::parse($assignment->due_at)->lessThan($deadline);
    }

    /**
     * A reminder this candidate has not received yet.
     *
     * Any pending reminder counts, whoever queued it. One written by the
     * automatic route a working day out and one written here say the same
     * thing from the same template, so the second would be a duplicate.
     */
    private function hasReminderWaiting(AssessmentAssignment $assignment): bool
    {
        return RecruitmentMessage::query()
            ->where('job_application_id', $assignment->job_application_id)
            ->where('template_key', RecruitmentTemplates::ASSESSMENT_NUDGE)
            ->where('status', RecruitmentMessage::PENDING)
            ->exists();
    }

    /**
     * Keyed on the deadline as well as the assignment, so a cohort moved to a
     * new date can be reminded of it, and keyed apart from the automatic
     * reminder so an already-sent one does not swallow this.
     */
    private function eventKey(AssessmentAssignment $assignment): string
    {
        return $this->outbox->eventKey(
            RecruitmentTemplates::ASSESSMENT_NUDGE,
            $assignment->application,
            (string) $assignment->id,
            (string) CarbonImmutable::parse($assignment->due_at ?? now())->timestamp,
            'notice',
        );
    }

    private function writeTo(AssessmentAssignment $assignment): void
    {
        $application = $assignment->application;

        $this->outbox->queue(RecruitmentTemplates::ASSESSMENT_NUDGE, $application, [
            'event_key'  => $this->eventKey($assignment),
            'action_url' => route('candidate.login'),
            'data'       => $this->outbox->baseData($application) + [
                'deadline' => $this->outbox->deadlineFor($application, $assignment->due_at ?? now()),
            ],
            'guard' => ['assignment' => $assignment->id, 'before_due' => true],
        ]);
    }

    /**
     * One date for the whole cohort, at the business close of play.
     *
     * Required even without --move, because it is what the report measures
     * everybody against: the question being asked is "is this the date they
     * will be told", and that needs the date.
     */
    private function resolveDeadline(): ?CarbonImmutable
    {
        $raw = trim((string) $this->option('deadline'));

        if ($raw === '') {
            $this->error('--deadline=YYYY-MM-DD is required: it is the date the cohort is measured against.');

            return null;
        }

        try {
            $at = CarbonImmutable::parse($raw, WorkingCalendar::timezone())
                ->setTimeFromTimeString((string) config('recruitment.assessment.due_at_time', '17:00'));
        } catch (\Throwable) {
            $this->error('That is not a date I can read. Use YYYY-MM-DD.');

            return null;
        }

        if ($at->isPast()) {
            $this->error('That deadline has already passed.');

            return null;
        }

        if (! WorkingCalendar::isWorkingDay($at)) {
            $this->warn($at->format('D j M') . ' is not a working day.');
        }

        return $at;
    }

    private function resolveActor(bool $move): ?User
    {
        $email = trim((string) $this->option('actor'));

        if ($email === '') {
            $this->error('--actor=<email> is required with --apply, so a send to a whole cohort has a name on it.');

            return null;
        }

        $actor = User::query()->where('email', $email)->first();

        if (! $actor) {
            $this->error('No user with that email address.');

            return null;
        }

        if ($move && ! RecruitmentPermission::allows($actor, RecruitmentPermission::MOVE_STAGE)) {
            $this->error($email . ' is not allowed to change a deadline.');

            return null;
        }

        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::REVIEW)) {
            $this->error($email . ' has no recruitment access.');

            return null;
        }

        return $actor;
    }
}
