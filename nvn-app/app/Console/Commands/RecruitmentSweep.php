<?php

namespace App\Console\Commands;

use App\Services\Recruitment\AssessmentService;
use App\Services\Recruitment\CandidateAccessService;
use Illuminate\Console\Command;

/**
 * The daily tidy-up: overdue assessments, and expired sign-in tokens.
 *
 * Two jobs, both of which are bookkeeping rather than decisions.
 *
 * Marking an assessment overdue changes a status and nothing else. It does not
 * reject anybody, it does not cancel the assignment, and the candidate's page
 * stays open — whether to accept late work is a decision a person makes by
 * extending or reopening, and this command deliberately has no opinion about
 * it. What it buys is that "overdue" is a thing a reviewer can see and filter
 * on, instead of something they have to work out from a date.
 *
 * There is no reminder work here, which can look like an omission. Reminders
 * are queued into the outbox when the invitation is sent, with their
 * `available_at` already set a working day before the deadline, and
 * nvn:recruitment-dispatch sends them when they come due — re-checking at that
 * moment whether the candidate has already submitted. A reminder command that
 * scanned for who to nudge would be a second implementation of that decision.
 */
class RecruitmentSweep extends Command
{
    protected $signature = 'nvn:recruitment-sweep';
    protected $description = 'Mark overdue assessments and delete spent candidate sign-in tokens';

    public function handle(AssessmentService $assessments, CandidateAccessService $access): int
    {
        if (! config('recruitment.enabled')) {
            $this->line('Recruitment is switched off; nothing to sweep.');

            return self::SUCCESS;
        }

        $overdue = $assessments->markOverdue();

        // Spent and expired tokens are deleted rather than kept: they are
        // single-use secrets whose only remaining value is to somebody who
        // should not have the database.
        $tokens = $access->pruneTokens();

        $this->line(sprintf(
            '%d %s marked overdue. %d spent sign-in %s deleted.',
            $overdue,
            str('assessment')->plural($overdue),
            $tokens,
            str('token')->plural($tokens),
        ));

        return self::SUCCESS;
    }
}
