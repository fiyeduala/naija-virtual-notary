<?php

namespace App\Console\Commands;

use App\Services\Recruitment\RecruitmentOutbox;
use Illuminate\Console\Command;

/**
 * Sends whatever recruitment email is due.
 *
 * This is the outbox drain. Nothing in the recruitment module ever hands a
 * message straight to the mail server: an email is written to
 * `recruitment_messages` inside the same transaction as the thing that caused
 * it, with a unique event key, and this command is what later picks it up. Two
 * consequences worth knowing:
 *
 *   - An application saves whether or not mail is working. A dead mail server
 *     produces a failed row somebody can retry, not a lost application.
 *   - A message is re-checked at the moment of sending, not at the moment of
 *     queueing. Withdrawn, rejected, hired, rescheduled, past its deadline —
 *     dispatchDue() cancels it with a reason instead of sending it. That is why
 *     a delayed invitation is safe to queue days ahead.
 *
 * Bounded by --limit because this runs on shared hosting where a cron run has
 * a wall-clock budget. Overlap is prevented by withoutOverlapping() on the
 * schedule and by a row-level lock inside dispatchDue(), so two runs cannot
 * both send the same email even if the first has not finished.
 */
class RecruitmentDispatch extends Command
{
    protected $signature = 'nvn:recruitment-dispatch
                            {--limit=25 : How many messages to attempt in this run}';

    protected $description = 'Send due recruitment emails from the outbox';

    public function handle(RecruitmentOutbox $outbox): int
    {
        if (! config('recruitment.enabled')) {
            $this->line('Recruitment is switched off; nothing to send.');

            return self::SUCCESS;
        }

        $tally = $outbox->dispatchDue(max(1, (int) $this->option('limit')));

        // Cancelled is not a failure. It is the module declining to email
        // somebody about a stage they are no longer at, which is the whole
        // point of deciding at send time.
        $this->line(sprintf(
            'Sent %d, failed %d, cancelled %d.',
            $tally['sent'],
            $tally['failed'],
            $tally['cancelled'],
        ));

        return self::SUCCESS;
    }
}
