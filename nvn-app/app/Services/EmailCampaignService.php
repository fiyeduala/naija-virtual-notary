<?php

namespace App\Services;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Turns a composed campaign into a recipient list and hands it to the queue.
 *
 * Two things this deliberately does NOT do:
 *
 *  - It does not send inline. A thousand-recipient send inside a Filament
 *    action would time the request out halfway through, and there would be no
 *    record of where it stopped.
 *  - It does not re-derive the recipient list at send time. The list is frozen
 *    into email_campaign_recipients when the campaign is queued, so a user who
 *    signs up mid-send does not receive an announcement about something that
 *    happened before they existed, and a user who opts out mid-send is not
 *    re-added on the next batch.
 */
class EmailCampaignService
{
    /**
     * Freeze the audience into recipient rows. Returns how many were written.
     *
     * Safe to call again on a campaign that already has rows: the unique index
     * on (campaign, user) means nobody is duplicated, and existing rows — with
     * their 'sent' marks — are left exactly as they are.
     */
    public function buildRecipients(EmailCampaign $campaign, array $userIds = []): int
    {
        $existing = $campaign->recipients()->pluck('user_id')->filter()->all();

        $query = $campaign->audience === 'individual'
            ? User::query()->whereNotNull('email')->whereIn('id', $userIds)
            // Opt-out applies to broadcasts only. See the migration.
            : EmailCampaign::audienceQuery($campaign->audience)->where('bulk_email_opt_out', false);

        $written = 0;

        $query->whereNotIn('id', $existing ?: [0])
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($campaign, &$written) {
                $now  = now();
                $rows = [];

                foreach ($users as $user) {
                    $email = trim((string) $user->email);

                    if ($email === '') {
                        continue;
                    }

                    $rows[] = [
                        'email_campaign_id' => $campaign->id,
                        'user_id'           => $user->id,
                        'email'             => $email,
                        'name'              => $user->full_name,
                        'status'            => 'pending',
                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ];
                }

                if ($rows !== []) {
                    EmailCampaignRecipient::insert($rows);
                    $written += count($rows);
                }
            });

        $campaign->forceFill([
            'total_recipients' => $campaign->recipients()->count(),
        ])->save();

        return $written;
    }

    /**
     * How many people this audience would reach right now, without writing
     * anything. The compose screen shows it so nobody sends blind.
     */
    public function audienceCount(string $audience, array $userIds = []): int
    {
        if ($audience === 'individual') {
            return User::query()->whereNotNull('email')->whereIn('id', $userIds)->count();
        }

        return EmailCampaign::audienceQuery($audience)->where('bulk_email_opt_out', false)->count();
    }

    /**
     * Move a draft to 'queued' and dispatch a job per pending recipient.
     *
     * Jobs are released onto the queue with a growing delay so a shared host's
     * per-hour SMTP limit is not tripped in the first ten seconds. Rate is in
     * emails per minute; 0 or less means send as fast as the worker can.
     */
    public function queue(EmailCampaign $campaign, ?int $perMinute = null): int
    {
        if ($campaign->isRunning()) {
            return 0;
        }

        $perMinute = $perMinute ?? \App\Support\Settings::int('email_rate_per_minute', 30);
        $gap       = $perMinute > 0 ? 60 / $perMinute : 0;

        $campaign->forceFill([
            'status'       => 'queued',
            'queued_at'    => $campaign->queued_at ?? now(),
            'completed_at' => null,
        ])->save();

        $dispatched = 0;

        $campaign->recipients()->where('status', 'pending')->orderBy('id')
            ->chunkById(500, function ($recipients) use ($gap, &$dispatched) {
                foreach ($recipients as $recipient) {
                    $job = \App\Jobs\SendCampaignEmail::dispatch($recipient->id);

                    if ($gap > 0) {
                        $job->delay(now()->addSeconds((int) round($dispatched * $gap)));
                    }

                    $dispatched++;
                }
            });

        AuditLogger::record('email_campaign.queued', 'email_campaign', $campaign->id, [
            'audience'   => $campaign->audience,
            'recipients' => $dispatched,
            'subject'    => $campaign->subject,
        ]);

        // Nobody to write to — don't leave it sitting in 'queued' forever.
        if ($dispatched === 0) {
            $this->finaliseIfDone($campaign->fresh());
        }

        return $dispatched;
    }

    /**
     * Stop a send that is under way. Jobs already on the queue check this before
     * sending, so a cancel takes effect on the next recipient rather than
     * needing the queue flushed.
     */
    public function cancel(EmailCampaign $campaign): void
    {
        $campaign->forceFill([
            'status'       => 'cancelled',
            'completed_at' => now(),
        ])->save();

        $campaign->recipients()->where('status', 'pending')
            ->update(['status' => 'skipped', 'error' => 'Campaign cancelled', 'updated_at' => now()]);

        AuditLogger::record('email_campaign.cancelled', 'email_campaign', $campaign->id, [
            'sent_so_far' => $campaign->sent_count,
        ]);
    }

    /** Recount from the ledger and close the campaign once nothing is pending. */
    public function finaliseIfDone(EmailCampaign $campaign): void
    {
        $counts = $campaign->recipients()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->pluck('total', 'status');

        $campaign->forceFill([
            'sent_count'   => (int) ($counts['sent'] ?? 0),
            'failed_count' => (int) ($counts['failed'] ?? 0),
        ])->save();

        if ((int) ($counts['pending'] ?? 0) > 0 || $campaign->status === 'cancelled') {
            return;
        }

        $campaign->forceFill([
            'status'       => 'sent',
            'completed_at' => now(),
        ])->save();

        AuditLogger::record('email_campaign.completed', 'email_campaign', $campaign->id, [
            'sent'   => $campaign->sent_count,
            'failed' => $campaign->failed_count,
        ]);
    }
}
