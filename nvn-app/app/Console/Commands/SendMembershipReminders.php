<?php

namespace App\Console\Commands;

use App\Models\NotaryProfile;
use App\Notifications\MembershipRenewalNotification;
use App\Support\AuditLogger;
use Illuminate\Console\Command;

/**
 * Tells partners their yearly membership is running out.
 *
 * Runs daily. The interesting part is what stops it becoming a daily nag: a
 * reminder goes out only on the milestones below, and membership_reminded_at
 * records the last one sent, so a partner hears about it a handful of times
 * across the last month rather than thirty.
 *
 * A lapse is not a punishment and this is not a threat — the notary keeps their
 * profile, their credentials and their completed work either way. What they lose
 * is their place in the marketplace, which is exactly what the fee buys.
 */
class SendMembershipReminders extends Command
{
    protected $signature = 'nvn:membership-reminders {--dry-run : List who would be emailed and send nothing}';
    protected $description = 'Email partners whose yearly membership is ending or has ended';

    /**
     * Days-remaining milestones that earn an email.
     *
     * Zero is the day it ends. Negative numbers are days since — one the day
     * after, then a week later, then a month later, and after that nothing:
     * a partner who has ignored five emails is not going to read a sixth.
     */
    private const MILESTONES = [30, 14, 7, 1, 0, -1, -7, -30];

    public function handle(): int
    {
        $profiles = NotaryProfile::query()
            ->where('is_system_native', false)
            ->whereNotNull('membership_expires_at')
            ->where('membership_expires_at', '<=', now()->addDays(max(self::MILESTONES)))
            ->where('membership_expires_at', '>=', now()->subDays(abs(min(self::MILESTONES))))
            ->with('user')
            ->get();

        $sent = 0;

        foreach ($profiles as $profile) {
            $days = $profile->membershipDaysLeft();

            if (! in_array($days, self::MILESTONES, true)) {
                continue;
            }

            // One email per milestone. Comparing dates rather than counting
            // sends keeps this correct if the scheduler fires twice in a day,
            // which shared hosting does more often than it should.
            if ($profile->membership_reminded_at?->isToday()) {
                continue;
            }

            if (! $profile->user) {
                $this->warn("Profile #{$profile->id} has no user — skipped.");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("would email {$profile->user->email} ({$days} days)");

                continue;
            }

            try {
                // Stamp first, same reason as the fallback watchdog: a send that
                // fails is better than one that repeats every run of the day.
                $profile->update(['membership_reminded_at' => now()]);

                $profile->user->notify(new MembershipRenewalNotification($profile));

                AuditLogger::record('notary.membership_reminder_sent', 'notary_profile', $profile->id, [
                    'days_left'  => $days,
                    'expires_at' => $profile->membership_expires_at->toDateString(),
                ]);

                $this->info("Reminded {$profile->user->email} ({$days} days).");
                $sent++;
            } catch (\Throwable $e) {
                $this->error("Failed to remind profile #{$profile->id}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->line($sent . ' reminder(s) sent.');

        return self::SUCCESS;
    }
}
