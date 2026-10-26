<?php

namespace App\Jobs;

use App\Enums\ApplicationStage;
use App\Exceptions\StageTransitionException;
use App\Models\JobApplication;
use App\Models\User;
use App\Notifications\Admin\BulkInvitationFinished;
use App\Services\Recruitment\AssessmentService;
use App\Support\AdminAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sending the exercise to more candidates than one web request can carry.
 *
 * A Filament bulk action runs inside the HTTP request that pressed the button.
 * That is the right shape for a page of twenty-five: the work finishes in a
 * second and the notification names whoever could not be included. It is the
 * wrong shape for four thousand, where each candidate costs a transaction that
 * replaces any open assignment, writes a new one with a snapshot of the
 * exercise, moves the stage, queues two emails and writes an audit row. Several
 * thousand of those in one page load exhausts the host's execution limit and
 * dies part-way, leaving the desk with a spinner and no idea who was reached.
 *
 * So above a threshold the selection is handed here instead. Three things
 * follow from that, and all three are deliberate:
 *
 *   - **The work is identical.** This calls the same AssessmentService::invite()
 *     the button calls, one candidate at a time, with the same stage guard. It
 *     is not a faster bulk path; it is the same path on a different thread. In
 *     particular the per-candidate permission check inside invite() still runs,
 *     against the stored actor, so a job cannot outlive the authority that
 *     started it.
 *   - **It re-dispatches rather than looping to the end.** The queue worker on
 *     this host is a cron-invoked `queue:work --stop-when-empty --max-time`,
 *     so a single job that ran for an hour would simply be killed mid-way. Each
 *     run takes a chunk, carries the tally forward and queues the remainder,
 *     which means the batch survives a worker being stopped at any point.
 *   - **It reports when it is finished.** The point of pressing the button once
 *     is not having to come back and check, so the last chunk alerts the desk
 *     with the counts and the reasons anybody was left out.
 *
 * Interrupting this is safe. Every candidate commits on its own, the queued
 * emails are keyed on a unique event key so none can be written twice, and
 * invite() replaces an open assignment rather than stacking a second one. A
 * batch that is killed and re-run finishes the job; it does not double-send.
 *
 * Note what this does NOT change: the rate at which those emails leave. That
 * is the outbox's own ceiling in `recruitment.outbox.per_hour`, and it applies
 * to this batch exactly as it would to one queued by hand.
 */
class InviteSelectionToAssessment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * How many candidates one run of this job handles.
     *
     * Small enough to finish well inside NVN_QUEUE_MAX_TIME with room for the
     * slowest transaction, large enough that four thousand does not mean four
     * thousand queue rows.
     */
    public const CHUNK = 50;

    /**
     * Above this many records, the bulk action queues instead of running inline.
     *
     * Chosen as a few pages of the table rather than a tuned number: below it,
     * the inline run is quick and its on-screen report is more useful than an
     * alert arriving later.
     */
    public const THRESHOLD = 150;

    /**
     * Retrying the job as a whole would re-walk candidates already invited.
     * They are individually idempotent, so that is survivable rather than
     * harmful, but a failure here means something systemic — so it surfaces
     * once instead of three times.
     */
    public int $tries = 1;

    /**
     * @param  list<int>  $applicationIds  Still to do.
     * @param  array<string, mixed>  $options  Delay and deadline, as chosen on screen.
     * @param  array<string, list<string>>  $skipped  Reason => references, carried between chunks.
     */
    public function __construct(
        public array $applicationIds,
        public array $options,
        public ?int $actorId,
        public int $invited = 0,
        public array $skipped = [],
    ) {}

    public function handle(AssessmentService $assessments): void
    {
        $actor = $this->actorId ? User::find($this->actorId) : null;

        $batch     = array_slice($this->applicationIds, 0, self::CHUNK);
        $remaining = array_slice($this->applicationIds, self::CHUNK);

        // Loaded by id rather than trusting the order: a candidate deleted or
        // anonymised since the button was pressed simply is not here, which is
        // the correct outcome and not an error worth reporting.
        $applications = JobApplication::query()->whereKey($batch)->get();

        foreach ($applications as $application) {
            try {
                if (! in_array($application->stage, [ApplicationStage::Applied, ApplicationStage::InitialReview], true)) {
                    throw new StageTransitionException('They are past the point where an exercise is sent.');
                }

                $assessments->invite($application, $actor, $this->options);

                $this->invited++;
            } catch (\Throwable $e) {
                $this->skipped[$e->getMessage()][] = $application->reference;
            }
        }

        if ($remaining !== []) {
            self::dispatch($remaining, $this->options, $this->actorId, $this->invited, $this->skipped);

            return;
        }

        AdminAlert::send(new BulkInvitationFinished($this->invited, $this->skipped));
    }
}
