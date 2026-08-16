<?php

namespace App\Console\Commands;

use App\Models\NotaryProfile;
use App\Notifications\NotaryAssetsReminder;
use App\Support\AuditLogger;
use Illuminate\Console\Command;

/**
 * Chases approved notaries who never uploaded their signature, stamp or seal.
 *
 * Approval already sends one email asking for them. This is the follow-up, and
 * it exists because the failure is completely silent otherwise: the notary is
 * approved, believes they are done, and simply never gets a booking — while the
 * admin has no signal at all beyond running nvn:notary-readiness by hand.
 *
 * Unlike the membership reminders there is no deadline to hang milestones off,
 * so the spacing is measured from the last send: a few days of grace after
 * approval, then a nudge a week apart, and after MAX_REMINDERS it stops. A
 * notary who has ignored five emails needs a phone call, not a sixth.
 */
class SendAssetReminders extends Command
{
    protected $signature = 'nvn:asset-reminders
        {--dry-run : List who would be emailed and send nothing}
        {--force : Ignore the spacing and the cap — for chasing someone by hand}';

    protected $description = 'Email approved notaries who still have no seal, stamp or signature on file';

    /** Days after approval before the first nudge — the approval email deserves a chance. */
    private const GRACE_DAYS = 2;

    /** Days between nudges. */
    private const INTERVAL_DAYS = 7;

    /** After this many, stop writing and let a human pick up the phone. */
    private const MAX_REMINDERS = 5;

    public function handle(): int
    {
        // Self-healing: someone who finished uploading goes back to zero, so
        // that if a file is lost later — a host move, a deletion — the whole
        // sequence starts again rather than resuming at its exhausted end.
        $this->resetCompleted();

        $profiles = NotaryProfile::query()
            ->where('verification_status', 'approved')
            ->where('is_system_native', false)
            ->with(['user', 'assets'])
            ->get()
            ->filter(fn (NotaryProfile $p) => ! $p->canSeal());

        $sent = 0;

        foreach ($profiles as $profile) {
            if (! $profile->user) {
                $this->warn("Profile #{$profile->id} has no user — skipped.");

                continue;
            }

            if (! $this->option('force') && ! $this->isDue($profile)) {
                continue;
            }

            $missing = implode(', ', $profile->missingSealingAssets());

            if ($this->option('dry-run')) {
                $this->line("would email {$profile->user->email} (missing: {$missing})");

                continue;
            }

            try {
                // Stamped before the send, same reason as the membership run: a
                // send that fails is better than one that repeats every run.
                $profile->update([
                    'assets_reminded_at'    => now(),
                    'assets_reminders_sent' => $profile->assets_reminders_sent + 1,
                ]);

                $profile->user->notify(new NotaryAssetsReminder($profile));

                AuditLogger::record('notary.asset_reminder_sent', 'notary_profile', $profile->id, [
                    'missing'  => $profile->missingSealingAssets(),
                    'reminder' => $profile->assets_reminders_sent,
                ]);

                $this->info("Reminded {$profile->user->email} (missing: {$missing}).");
                $sent++;
            } catch (\Throwable $e) {
                $this->error("Failed to remind profile #{$profile->id}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->line($sent . ' reminder(s) sent.');

        return self::SUCCESS;
    }

    /**
     * Long enough since approval, long enough since the last one, and not yet
     * out of patience.
     */
    private function isDue(NotaryProfile $profile): bool
    {
        if ($profile->assets_reminders_sent >= self::MAX_REMINDERS) {
            return false;
        }

        // A profile approved before this feature existed has no approved_at in
        // some imported rows. Treat that as "long ago" rather than skipping
        // them forever — they are precisely the people who need chasing.
        if ($profile->approved_at?->isAfter(now()->subDays(self::GRACE_DAYS))) {
            return false;
        }

        return $profile->assets_reminded_at === null
            || $profile->assets_reminded_at->isBefore(now()->subDays(self::INTERVAL_DAYS));
    }

    private function resetCompleted(): void
    {
        NotaryProfile::query()
            ->where('assets_reminders_sent', '>', 0)
            ->with('assets')
            ->get()
            ->filter(fn (NotaryProfile $p) => $p->canSeal())
            ->each(fn (NotaryProfile $p) => $p->update([
                'assets_reminded_at'    => null,
                'assets_reminders_sent' => 0,
            ]));
    }
}
