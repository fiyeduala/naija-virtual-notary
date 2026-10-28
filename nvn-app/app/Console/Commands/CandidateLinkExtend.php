<?php

namespace App\Console\Commands;

use App\Models\CandidateLoginToken;
use App\Services\Recruitment\CandidateAccessService;
use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Put time back on sign-in links that expired while the host held the email.
 *
 * A one-off repair, not a scheduled job, and it exists because of a specific
 * failure: a link's thirty-minute life started when the candidate asked for
 * it, but the mail sat in the host's own relay queue for hours behind a
 * volume of invitations that exceeded the hourly cap. The candidate received
 * a link that had been dead since before it arrived, and the only clue on our
 * side was an outbox row marked sent — because `sent_at` records the handoff
 * to the mail server, which is the last moment we know anything.
 *
 * Lengthening the setting fixes every link minted afterwards and does nothing
 * for the ones already gone, because the expiry is a column on the row rather
 * than a calculation against the current configuration. That is the right
 * design — a link's life should not change under it — and it is why this
 * command has to exist to say, deliberately and once, that these particular
 * rows get more time.
 *
 * What it does not do is revive everything it finds. Only the newest
 * unconsumed link per candidate is extended, and the older ones are marked
 * spent: `issue()` keeps exactly one live link per candidate, and a repair
 * that left four of them live for the same person would quietly undo that.
 *
 * Nothing is sent. A candidate whose link is extended here finds the message
 * already in their inbox working again; nobody is written to twice.
 */
class CandidateLinkExtend extends Command
{
    protected $signature = 'nvn:candidate-link-extend
                            {--days=3 : Only links issued within this many days}
                            {--apply : Write the change; without this the command only reports}';

    protected $description = 'Give unused candidate sign-in links that expired in transit a fresh expiry';

    public function handle(): int
    {
        if (! config('recruitment.enabled')) {
            $this->line('Recruitment is switched off; nothing to extend.');

            return self::SUCCESS;
        }

        $days    = max(1, (int) $this->option('days'));
        $apply   = (bool) $this->option('apply');
        $minutes = CandidateAccessService::lifetimeMinutes();
        $freshAt = now()->addMinutes($minutes);

        // Unconsumed, issued recently, and expired. A link that is still live
        // needs nothing from this command, and one from last month is not a
        // casualty of the backlog — it is somebody who never came back.
        $candidates = CandidateLoginToken::query()
            ->whereNull('consumed_at')
            ->where('created_at', '>=', now()->subDays($days))
            ->where('expires_at', '<=', now())
            ->distinct()
            ->pluck('candidate_id');

        if ($candidates->isEmpty()) {
            $this->line('No expired, unused sign-in links were issued in the last ' . $days . ' ' . str('day')->plural($days) . '.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d %s %s an expired, unused link from the last %d %s.',
            $candidates->count(),
            str('candidate')->plural($candidates->count()),
            $candidates->count() === 1 ? 'has' : 'have',
            $days,
            str('day')->plural($days),
        ));

        if (! $apply) {
            $this->line('Nothing was changed. Re-run with --apply to extend them to ' . CandidateAccessService::lifetimeInWords($minutes) . ' from now.');

            return self::SUCCESS;
        }

        $extended = 0;
        $retired  = 0;

        foreach ($candidates as $candidateId) {
            DB::transaction(function () use ($candidateId, $days, $freshAt, &$extended, &$retired) {
                $tokens = CandidateLoginToken::query()
                    ->where('candidate_id', $candidateId)
                    ->whereNull('consumed_at')
                    ->where('created_at', '>=', now()->subDays($days))
                    ->where('expires_at', '<=', now())
                    ->lockForUpdate()
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->get();

                $newest = $tokens->shift();

                if (! $newest) {
                    return;
                }

                $newest->forceFill(['expires_at' => $freshAt])->save();
                $extended++;

                foreach ($tokens as $older) {
                    $older->forceFill(['consumed_at' => now()])->save();
                    $retired++;
                }
            });
        }

        // One audit row for the repair rather than one per candidate: this is
        // a single operational decision, and a thousand rows saying the same
        // thing would bury the entries around it.
        AuditLogger::record('candidate_access.extended', null, null, [
            'extended'   => $extended,
            'retired'    => $retired,
            'within_days' => $days,
            'expires_at' => $freshAt->toIso8601String(),
        ]);

        $this->info(sprintf(
            '%d %s extended to %s. %d older unused %s marked spent.',
            $extended,
            str('link')->plural($extended),
            $freshAt->timezone(config('app.timezone'))->format('D j M, g:ia'),
            $retired,
            str('link')->plural($retired),
        ));

        return self::SUCCESS;
    }
}
