<?php

namespace App\Console\Commands;

use App\Models\CandidateLoginToken;
use App\Models\RecruitmentMessage;
use App\Models\User;
use App\Services\Recruitment\RecruitmentOutbox;
use App\Support\AuditLogger;
use App\Support\RecruitmentPermission;
use App\Support\RecruitmentTemplates;
use App\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Correct one false sentence in an email that has already been read.
 *
 * The exercise retry email told candidates "if you still have our earlier
 * email, the link in it works again too". That was not true and could not be
 * made true: a sign-in token is one use and thirty minutes, and issuing a new
 * link marks every older one spent. So a candidate who followed that sentence
 * met a dead page and had no reason to think the fault was ours. The wording
 * was fixed in the template, but by then it had gone to the front of the
 * queue; this writes to the people who read the wrong version.
 *
 * Four things it does deliberately:
 *
 *   - It is scoped by when the retry email was SENT, not when it was queued.
 *     The template renders at send time, so everybody whose copy left after
 *     the fix landed already received the correct wording and must not be
 *     told there was a mistake in an email that did not contain one.
 *   - It skips anybody who has signed in since. A consumed login token is the
 *     proof: they are inside the exercise, the sentence cost them nothing, and
 *     a correction now would only confuse. This is the one piece of scope the
 *     owner chose explicitly.
 *   - It carries no deadline change and no token, only route('candidate.login').
 *     The deadline the retry email named still stands, and saying so is the
 *     whole point — this is a correction, not a second extension.
 *   - It is paceable rather than priority, and guarded on the assignment with
 *     `before_due`. A correction that arrives after the deadline it refers to
 *     has passed is worse than none, so it is cancelled rather than sent.
 *
 * Running it twice is safe: the event key is stable per assignment, so the
 * second run finds every message already written and does nothing.
 */
class AssessmentCorrection extends Command
{
    protected $signature = 'nvn:assessment-correction
                            {--before= : Only retry emails sent before this moment, as YYYY-MM-DD or "YYYY-MM-DD HH:MM" — the moment the corrected wording went live}
                            {--actor= : Email of the administrator the run is recorded against}
                            {--apply : Queue the emails; without this the command only reports}';

    protected $description = 'Write once to the candidates whose retry email claimed their old sign-in link worked again';

    public function __construct(private RecruitmentOutbox $outbox)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('recruitment.enabled')) {
            $this->line('Recruitment is switched off; nothing to do.');

            return self::SUCCESS;
        }

        $before = $this->resolveBefore();

        if (! $before) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $actor = null;

        if ($apply) {
            $actor = $this->resolveActor();

            if (! $actor) {
                return self::FAILURE;
            }
        }

        // Sent, not queued. A pending retry still in the outbox will render
        // the corrected template when its turn comes, so its candidate never
        // saw the false sentence and is not in this cohort.
        $retries = RecruitmentMessage::query()
            ->where('template_key', RecruitmentTemplates::ASSESSMENT_RETRY)
            ->where('status', RecruitmentMessage::SENT)
            ->whereNotNull('sent_at')
            ->where('sent_at', '<', $before)
            ->whereNotNull('job_application_id')
            ->with(['application.candidate', 'application.vacancy', 'application.assignments'])
            ->orderBy('id')
            ->get();

        if ($retries->isEmpty()) {
            $this->line('No retry emails were sent before ' . $before->format('D j M, g:ia') . '.');

            return self::SUCCESS;
        }

        $lastSignIn = $this->lastSignIns($retries->pluck('candidate_id')->filter()->unique()->all());

        $tally = ['already' => 0, 'signed_in' => 0, 'closed' => 0, 'overdue' => 0, 'todo' => 0];
        $todo  = [];

        foreach ($retries as $retry) {
            $reason = $this->skipReason($retry, $lastSignIn);

            if ($reason) {
                $tally[$reason]++;

                continue;
            }

            $tally['todo']++;
            $todo[] = $retry;
        }

        $this->line(sprintf(
            '%d retry %s sent before %s. %d already corrected, %d have signed in since, '
            . '%d no longer open, %d past their deadline. %d to write to.',
            $retries->count(),
            str('email')->plural($retries->count()),
            $before->format('D j M, g:ia'),
            $tally['already'],
            $tally['signed_in'],
            $tally['closed'],
            $tally['overdue'],
            $tally['todo'],
        ));

        if (! $apply) {
            $this->line('Nothing was queued. Re-run with --apply --actor=<admin email> to write to them.');

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($todo as $retry) {
            $this->writeTo($retry);
            $queued++;
        }

        AuditLogger::record('recruitment_assessment.corrected', null, null, [
            'queued'    => $queued,
            'already'   => $tally['already'],
            'signed_in' => $tally['signed_in'],
            'closed'    => $tally['closed'],
            'overdue'   => $tally['overdue'],
            'before'    => $before->toIso8601String(),
        ], $actor->id);

        $this->info(sprintf('%d correction %s queued.', $queued, str('email')->plural($queued)));
        $this->line('The outbox paces them; nothing is sent by this command.');

        return self::SUCCESS;
    }

    /**
     * The last time each candidate exchanged a link for a session.
     *
     * One query rather than one per candidate, and a consumed token is the
     * only record that a sign-in happened — the session itself leaves nothing
     * behind to count.
     *
     * @param  array<int, int>  $candidateIds
     * @return array<int, CarbonImmutable>
     */
    private function lastSignIns(array $candidateIds): array
    {
        if ($candidateIds === []) {
            return [];
        }

        $rows = CandidateLoginToken::query()
            ->whereIn('candidate_id', $candidateIds)
            ->whereNotNull('consumed_at')
            ->groupBy('candidate_id')
            ->selectRaw('candidate_id, MAX(consumed_at) as last_at')
            ->pluck('last_at', 'candidate_id');

        $out = [];

        foreach ($rows as $candidateId => $at) {
            if ($at) {
                $out[(int) $candidateId] = CarbonImmutable::parse($at);
            }
        }

        return $out;
    }

    /**
     * Why this candidate is not written to, or null if they are.
     *
     * Read in order of what it would cost to get wrong: a second correction,
     * then a correction to somebody it never misled, then one to somebody
     * whose exercise is over, then one naming a deadline that has gone.
     *
     * @param  array<int, CarbonImmutable>  $lastSignIn
     */
    private function skipReason(RecruitmentMessage $retry, array $lastSignIn): ?string
    {
        if ($this->alreadyWritten($retry)) {
            return 'already';
        }

        $assignment = $this->assignmentFor($retry);
        $application = $retry->application;

        if (! $assignment || ! $application) {
            return 'closed';
        }

        $candidate = $application->candidate;

        if (! $candidate || $candidate->isAnonymised() || ! $candidate->email) {
            return 'closed';
        }

        if ($application->isWithdrawn() || $application->isRejected()) {
            return 'closed';
        }

        if ($assignment->cancelled_at || $assignment->submitted_at) {
            return 'closed';
        }

        // Signed in since their retry email left: they are in the exercise and
        // the sentence about the old link cost them nothing.
        $signedInAt = $lastSignIn[(int) $retry->candidate_id] ?? null;

        if ($signedInAt && $retry->sent_at && $signedInAt->greaterThanOrEqualTo($retry->sent_at)) {
            return 'signed_in';
        }

        // The email says their deadline has not changed, which has to be true
        // of a deadline that is still standing. The payload guard catches one
        // that passes between here and the send.
        if (! $assignment->due_at || $assignment->due_at->isPast()) {
            return 'overdue';
        }

        return null;
    }

    /**
     * The assignment the retry email was about.
     *
     * Taken from the guard the retry carried rather than looked up afresh:
     * that is the assignment whose deadline the candidate was told, and a
     * candidate with two assignments must be corrected about the right one.
     */
    private function assignmentFor(RecruitmentMessage $retry): ?\App\Models\AssessmentAssignment
    {
        $guard = (array) (($retry->payload ?? [])['guard'] ?? []);
        $id    = $guard['assignment'] ?? null;

        if (! $id) {
            return null;
        }

        return $retry->application?->assignments->firstWhere('id', (int) $id);
    }

    /**
     * The stable key that makes a second run a no-op.
     *
     * Keyed on the retry message rather than on the assignment, so it can be
     * computed before the assignment is looked up — and because one retry
     * email is exactly what is being corrected, there is one correction per
     * retry by construction.
     */
    private function eventKey(RecruitmentMessage $retry): string
    {
        return $this->outbox->eventKey(
            RecruitmentTemplates::ASSESSMENT_FIX,
            $retry->application,
            (string) ($retry->id),
        );
    }

    private function alreadyWritten(RecruitmentMessage $retry): bool
    {
        return RecruitmentMessage::query()
            ->where('event_key', Str::limit($this->eventKey($retry), 185, ''))
            ->exists();
    }

    private function writeTo(RecruitmentMessage $retry): void
    {
        $application = $retry->application;
        $assignment  = $this->assignmentFor($retry);

        $this->outbox->queue(RecruitmentTemplates::ASSESSMENT_FIX, $application, [
            'event_key'  => $this->eventKey($retry),
            'action_url' => route('candidate.login'),
            'data'       => $this->outbox->baseData($application) + [
                'deadline' => $this->outbox->deadlineFor($application, $assignment->due_at),
            ],
            'guard'      => ['assignment' => $assignment->id, 'before_due' => true],
        ]);
    }

    /**
     * The moment the corrected wording went live.
     *
     * Required, with no default, because only an owner knows when the deploy
     * landed and guessing it either corrects people who were never misled or
     * leaves out people who were.
     */
    private function resolveBefore(): ?CarbonImmutable
    {
        $raw = trim((string) $this->option('before'));

        if ($raw === '') {
            $this->error('--before="YYYY-MM-DD HH:MM" is required: the moment the corrected template went live.');

            return null;
        }

        try {
            $at = CarbonImmutable::parse($raw, WorkingCalendar::timezone());
        } catch (\Throwable) {
            $this->error('That is not a moment I can read. Use YYYY-MM-DD or "YYYY-MM-DD HH:MM".');

            return null;
        }

        if ($at->isFuture()) {
            $this->error('That moment has not happened yet.');

            return null;
        }

        return $at;
    }

    private function resolveActor(): ?User
    {
        $email = trim((string) $this->option('actor'));

        if ($email === '') {
            $this->error('--actor=<email> is required with --apply, so the run is recorded against a person.');

            return null;
        }

        $actor = User::query()->where('email', $email)->first();

        if (! $actor) {
            $this->error('No user with that email address.');

            return null;
        }

        if (! RecruitmentPermission::allows($actor, RecruitmentPermission::MOVE_STAGE)) {
            $this->error($email . ' is not allowed to write to a cohort of candidates.');

            return null;
        }

        return $actor;
    }
}
