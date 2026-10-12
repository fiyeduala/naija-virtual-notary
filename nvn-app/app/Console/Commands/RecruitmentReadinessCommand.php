<?php

namespace App\Console\Commands;

use App\Support\RecruitmentReadiness;
use Illuminate\Console\Command;

/**
 * Is the recruitment module fit to be pointed at real people?
 *
 * The same list the Launch readiness screen shows, in a terminal, because the
 * two things most likely to be wrong — the mail transport and whether cron is
 * running — are things you check from a shell on the server rather than from a
 * browser.
 *
 * Read-only. Prints; changes nothing.
 *
 * Exits non-zero when something would actually go wrong, so it can be the last
 * line of a deployment script. Unfinished items do not fail the run: the
 * retention policy being unset is a deliberate state, not a broken one, and a
 * deploy that refused to finish over it would teach somebody to pass --force.
 */
class RecruitmentReadinessCommand extends Command
{
    protected $signature = 'nvn:recruitment-readiness
                            {--quiet-ok : Print only what is unfinished or wrong}';

    protected $description = 'Check whether recruitment is configured well enough to open a vacancy';

    public function handle(): int
    {
        $checks = RecruitmentReadiness::checks();
        $tally  = RecruitmentReadiness::tally();

        foreach ($checks as $check) {
            if ($check['status'] === RecruitmentReadiness::OK && $this->option('quiet-ok')) {
                continue;
            }

            $marker = match ($check['status']) {
                RecruitmentReadiness::OK   => '<fg=green>  ok</>',
                RecruitmentReadiness::WARN => '<fg=yellow>todo</>',
                default                    => '<fg=red;options=bold>FAIL</>',
            };

            $this->line('<fg=gray>[</>' . $marker . '<fg=gray>]</> ' . $check['label']);
            $this->line('       ' . wordwrap($check['detail'], 96, "\n       "));

            if ($check['fix']) {
                $this->line('       <fg=gray>→ ' . wordwrap($check['fix'], 92, "\n         ") . '</>');
            }

            $this->newLine();
        }

        $this->line(sprintf(
            '%d in order, %d unfinished, %d would go wrong.',
            $tally['ok'],
            $tally['warn'],
            $tally['fail'],
        ));

        if ($tally['fail'] > 0) {
            $this->error('Do not open a vacancy to the public while anything above says FAIL.');

            return self::FAILURE;
        }

        // Said every time rather than only when something is missing: these
        // are the facts this command cannot establish, and a clean run should
        // not be mistaken for a tested one.
        $this->comment('Not checked here: that your mail actually arrives, that your cron really '
            . 'fires, or that your privacy notice is lawful. Send yourself one of each email before '
            . 'you publish anything.');

        return self::SUCCESS;
    }
}
