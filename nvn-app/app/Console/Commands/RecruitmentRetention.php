<?php

namespace App\Console\Commands;

use App\Services\Recruitment\RetentionService;
use Illuminate\Console\Command;

/**
 * Deletes applications that are older than the approved retention period.
 *
 * This command does nothing at all until somebody sets that period, and that
 * is the point. A retention period has legal weight; a default invented in a
 * config file would be this software quietly setting policy for somebody
 * else's business, so there is no default and the command says why it is
 * refusing rather than appearing to work.
 *
 * What it will not touch: anybody who was hired, and anybody whose application
 * is still live. Age is measured from the last thing that happened to the
 * application, not from when it arrived, so a candidate interviewed six months
 * after applying has a six-month-newer record.
 *
 * One limitation that belongs in the open: this deletes from the live system.
 * Database backups taken before a purge still hold the data until they age out
 * on the host's own cycle, which this application neither controls nor can
 * report on.
 *
 * --dry-run prints what would go and deletes nothing, which is how this should
 * be run the first time.
 */
class RecruitmentRetention extends Command
{
    protected $signature = 'nvn:recruitment-retention
                            {--limit=50 : How many applications to purge in this run}
                            {--dry-run : List what would be deleted and delete nothing}';

    protected $description = 'Purge applications past the approved retention period';

    public function handle(RetentionService $retention): int
    {
        if (! config('recruitment.enabled')) {
            $this->line('Recruitment is switched off; nothing to purge.');

            return self::SUCCESS;
        }

        $blocked = $retention->blockedReason();

        if ($this->option('dry-run')) {
            $preview = $retention->preview();

            if ($blocked) {
                // preview() answers an impossible query when no period is set,
                // so the zeros below are honest rather than reassuring. Say
                // which it is.
                $this->warn($blocked);
            }

            $this->line(sprintf(
                'Period: %s. Would delete %d %s, covering %d %s and %d stored %s.',
                $preview['months'] === null ? 'not set' : $preview['months'] . ' months',
                $preview['applications'],
                str('application')->plural($preview['applications']),
                $preview['candidates'],
                str('candidate')->plural($preview['candidates']),
                $preview['documents'],
                str('file')->plural($preview['documents']),
            ));

            if ($preview['oldest']) {
                $this->line('Oldest eligible record last changed ' . $preview['oldest'] . '.');
            }

            $this->line('A candidate whose only application is deleted is anonymised rather than '
                . 'left pointing at nothing, and the audit trail keeps that it happened without '
                . 'keeping what was deleted.');

            return self::SUCCESS;
        }
        if ($blocked) {
            // Not a failure: refusing to delete without a policy is the
            // correct behaviour, and a non-zero exit would fill a cron mailbox
            // with alarms about something working as designed.
            $this->warn($blocked);

            return self::SUCCESS;
        }

        $purged = $retention->runAutomatic(max(1, (int) $this->option('limit')));

        $this->line(sprintf('%d %s purged.', $purged, str('application')->plural($purged)));

        return self::SUCCESS;
    }
}
