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
 * Write once to everybody whose exercise invitation arrived unusable.
 *
 * The invitations went out faster than the host's mail server would relay
 * them, so they queued for hours, and the thirty-minute sign-in link inside
 * each one was dead before the candidate ever saw it. Most of them never got
 * in, and their deadlines then passed while they were locked out. This is the
 * apology and the second chance, sent once per person.
 *
 * Three things it does deliberately:
 *
 *   - It moves everybody in scope to one named date, not only the ones
 *     already overdue. A candidate whose deadline has not yet run out still
 *     lost the days they spent unable to sign in, and the email says their
 *     deadline moved, which has to be true for every person who receives it.
 *     One shared date also means the office has one answer to give when they
 *     ring up, rather than one per candidate.
 *   - It sends ASSESSMENT_RETRY, which is paceable rather than priority, and
 *     carries route('candidate.login') rather than a token. Mail that is held
 *     for a day is still correct on arrival, which is exactly what the
 *     original invitation got wrong. Minting a fresh SIGN_IN for twelve
 *     hundred people would bypass the rate limiter, breach the host's hourly
 *     cap and get the account's mail suspended.
 *   - It guards each message on the assignment, so anybody who submits while
 *     their copy is still queued has it cancelled rather than delivered.
 *
 * Running it twice is safe and does nothing the second time: the event key is
 * stable per assignment, so an assignment that already has its message is
 * skipped before anything is extended.
 */
class AssessmentFollowUp extends Command
{
    protected $signature = 'nvn:assessment-followup
                            {--deadline= : The date everybody gets, as YYYY-MM-DD, at the business close of play}
                            {--within=14 : Only assignments invited within this many days}
                            {--actor= : Email of the administrator the extension is recorded against}
                            {--apply : Write the change; without this the command only reports}';

    protected $description = 'Extend and write once to candidates whose exercise invitation arrived with a dead link';

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

        $deadline = $this->resolveDeadline();

        if (! $deadline) {
            return self::FAILURE;
        }

        // An extension is somebody's decision and the audit row has a column
        // for whose. extend() refuses a null actor, and recording twelve
        // hundred deadline changes against nobody would be worse than that.
        $actor = null;

        if ($apply) {
            $actor = $this->resolveActor();

            if (! $actor) {
                return self::FAILURE;
            }
        }

        // Invited and overdue, but deliberately not reopened: scopeOpen()
        // counts that third one as live, and it is not this cohort. Reopening
        // follows a submission a reviewer chose to hand back, with a deadline
        // they set on purpose and a reason attached — overwriting it with a
        // blanket date and an apology for an email they received perfectly
        // well would undo somebody's decision and tell them an untrue story.
        $assignments = AssessmentAssignment::query()
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

        if ($assignments->isEmpty()) {
            $this->line('No unsubmitted exercises were issued in the last ' . $within . ' ' . str('day')->plural($within) . '.');

            return self::SUCCESS;
        }

        $already = $assignments->filter(fn ($assignment) => $this->alreadyWritten($assignment));
        $todo    = $assignments->reject(fn ($assignment) => $this->alreadyWritten($assignment));

        $this->line(sprintf(
            '%d unsubmitted %s from the last %d %s. %d already written to, %d to go.',
            $assignments->count(),
            str('exercise')->plural($assignments->count()),
            $within,
            str('day')->plural($within),
            $already->count(),
            $todo->count(),
        ));

        if (! $apply) {
            $this->line('Nothing was changed. Re-run with --apply --actor=<admin email> to move them all to '
                . $deadline->format('D j M, g:ia') . ' and queue the email.');

            return self::SUCCESS;
        }

        $extended = 0;
        $queued   = 0;
        $skipped  = 0;

        foreach ($todo as $assignment) {
            try {
                $assignment = $this->assessments->extend(
                    $assignment,
                    $actor,
                    1,
                    'Invitation email was delayed and the sign-in link expired before it arrived.',
                    $deadline,
                );
            } catch (\Throwable $e) {
                // One assignment that will not extend must not stop the other
                // twelve hundred, and the reference is enough to find it after.
                $this->warn($assignment->application->reference . ': ' . $e->getMessage());
                $skipped++;

                continue;
            }

            $extended++;
            $this->writeTo($assignment);
            $queued++;
        }

        AuditLogger::record('recruitment_assessment.followed_up', null, null, [
            'extended'    => $extended,
            'queued'      => $queued,
            'skipped'     => $skipped,
            'already'     => $already->count(),
            'deadline'    => $deadline->toIso8601String(),
            'within_days' => $within,
        ], $actor->id);

        $this->info(sprintf(
            '%d %s moved to %s and %d %s queued. %d skipped.',
            $extended,
            str('deadline')->plural($extended),
            $deadline->format('D j M, g:ia'),
            $queued,
            str('email')->plural($queued),
            $skipped,
        ));

        $this->line('The outbox paces them; nothing is sent by this command.');

        return self::SUCCESS;
    }

    /** The stable key that makes a second run a no-op. */
    private function eventKey(AssessmentAssignment $assignment): string
    {
        return $this->outbox->eventKey(
            RecruitmentTemplates::ASSESSMENT_RETRY,
            $assignment->application,
            (string) $assignment->id,
        );
    }

    private function alreadyWritten(AssessmentAssignment $assignment): bool
    {
        return RecruitmentMessage::query()
            ->where('event_key', Str::limit($this->eventKey($assignment), 185, ''))
            ->exists();
    }

    private function writeTo(AssessmentAssignment $assignment): void
    {
        $application = $assignment->application;

        // An absent effort line drops its own paragraph rather than reaching
        // an inbox as braces, so it is left out rather than passed empty.
        $data = $this->outbox->baseData($application) + [
            'deadline' => $this->outbox->deadlineFor($application, $assignment->due_at),
        ];

        if ($effort = $assignment->effortLine()) {
            $data['effort'] = $effort;
        }

        $this->outbox->queue(RecruitmentTemplates::ASSESSMENT_RETRY, $application, [
            'event_key'  => $this->eventKey($assignment),
            'action_url' => route('candidate.login'),
            'data'       => $data,
            'guard'      => ['assignment' => $assignment->id, 'before_due' => true],
        ]);
    }

    /**
     * One date for the whole cohort, at the business close of play.
     *
     * A date rather than a number of days because this is the answer to a
     * question people will ask on the phone, and "five working days from
     * whenever you happened to be invited" is five different answers. The hour
     * and the timezone are the ones every other deadline already uses, so a
     * candidate in Toronto reads the same 5pm in Lagos they would have read
     * from an ordinary invitation.
     */
    private function resolveDeadline(): ?CarbonImmutable
    {
        $raw = trim((string) $this->option('deadline'));

        if ($raw === '') {
            $this->error('--deadline=YYYY-MM-DD is required, so every candidate is told the same date.');

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
            // Not refused: an owner may well want a Saturday. But a deadline
            // nobody is in the office for is worth saying out loud.
            $this->warn($at->format('D j M') . ' is not a working day.');
        }

        return $at;
    }

    private function resolveActor(): ?User
    {
        $email = trim((string) $this->option('actor'));

        if ($email === '') {
            $this->error('--actor=<email> is required with --apply, so the extension is recorded against a person.');

            return null;
        }

        $actor = User::query()->where('email', $email)->first();

        if (! $actor) {
            $this->error('No user with that email address.');

            return null;
        }

        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::MOVE_STAGE)) {
            $this->error($email . ' is not allowed to change a deadline.');

            return null;
        }

        return $actor;
    }
}
