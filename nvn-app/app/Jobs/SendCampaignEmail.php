<?php

namespace App\Jobs;

use App\Mail\AdminBroadcastMail;
use App\Models\EmailCampaignRecipient;
use App\Services\EmailCampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one campaign email to one person.
 *
 * One job per recipient rather than one job per campaign, on purpose: a mail
 * server that rejects a single address must not take the other nine hundred
 * down with it, and a worker killed mid-run loses at most one email.
 *
 * The recipient row is the guard against double sending. A row that is not
 * 'pending' has already been decided — the job returns without sending, so a
 * retried job, a re-queued campaign, or two workers racing cannot produce two
 * copies of the same announcement.
 */
class SendCampaignEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Back off a minute between attempts: most send failures are transient. */
    public array $backoff = [60, 300];

    public function __construct(public int $recipientId) {}

    public function handle(EmailCampaignService $service): void
    {
        $recipient = EmailCampaignRecipient::with('campaign')->find($this->recipientId);

        if (! $recipient || ! $recipient->campaign) {
            return;
        }

        if ($recipient->status !== 'pending') {
            return;
        }

        $campaign = $recipient->campaign;

        // Cancelled while this job sat on the queue.
        if ($campaign->status === 'cancelled') {
            $recipient->forceFill(['status' => 'skipped', 'error' => 'Campaign cancelled'])->save();

            return;
        }

        if ($campaign->status === 'queued') {
            $campaign->forceFill(['status' => 'sending'])->save();
        }

        try {
            Mail::to($recipient->email, $recipient->name)->send(new AdminBroadcastMail($recipient));

            $recipient->forceFill([
                'status'  => 'sent',
                'sent_at' => now(),
                'error'   => null,
            ])->save();
        } catch (\Throwable $e) {
            // Let the queue retry until the attempts are spent; only the last
            // one writes the failure to the ledger (see failed()).
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            $this->markFailed($recipient, $e);
        }

        $service->finaliseIfDone($campaign->fresh());
    }

    /** The queue's own last word — reached when the job errors outside handle(). */
    public function failed(\Throwable $e): void
    {
        $recipient = EmailCampaignRecipient::with('campaign')->find($this->recipientId);

        if ($recipient && $recipient->status === 'pending') {
            $this->markFailed($recipient, $e);

            if ($recipient->campaign) {
                app(EmailCampaignService::class)->finaliseIfDone($recipient->campaign->fresh());
            }
        }
    }

    private function markFailed(EmailCampaignRecipient $recipient, \Throwable $e): void
    {
        Log::warning("[email] campaign {$recipient->email_campaign_id} could not reach {$recipient->email}: " . $e->getMessage());

        $recipient->forceFill([
            'status' => 'failed',
            'error'  => mb_substr($e->getMessage(), 0, 500),
        ])->save();
    }
}
