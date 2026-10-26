<?php

namespace App\Notifications\Admin;

use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A large batch of exercise invitations has finished being placed.
 *
 * This is the receipt for a bulk action that was too big to run on screen. It
 * exists so that pressing the button once is actually enough — the alternative
 * is an admin reloading the applications table trying to work out whether the
 * batch is still going.
 *
 * Two things it is careful about:
 *
 *   - "Placed", not "sent". These are queued into the outbox, which drains at
 *     its own configured rate; the body says so, because a notification that
 *     read "4,000 invitations sent" an instant after the button was pressed
 *     would be a lie about the thing the rate limit exists to control.
 *   - It carries references and counts, never a name or an email address. An
 *     admin alert is read on a phone lock screen, and a candidate's name does
 *     not belong there.
 *
 * No mail channel, matching the rest of the admin alerts: this is worth a
 * glance, not an email.
 */
class BulkInvitationFinished extends Notification
{
    use Queueable;

    /** @param array<string, list<string>> $skipped Reason => references. */
    public function __construct(
        public int $invited,
        public array $skipped = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'bulk_invitations_placed',
            'invited' => $this->invited,
            'skipped' => $this->skippedCount(),
            'reasons' => $this->reasons(),
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        $body = number_format($this->invited) . ' queued for sending';

        if ($this->skippedCount() > 0) {
            $body .= ', ' . number_format($this->skippedCount()) . ' left alone';
        }

        return [
            'title' => 'Exercise invitations placed',
            'body'  => $body . '. They go out at the configured hourly rate.',
            'url'   => route('filament.admin.resources.job-applications.index'),
        ];
    }

    private function skippedCount(): int
    {
        return array_sum(array_map('count', $this->skipped));
    }

    /**
     * Why people were left out, as counts rather than lists.
     *
     * A batch this size can skip hundreds for one ordinary reason — a vacancy
     * with no exercise attached — and several hundred references in a stored
     * notification is unreadable and keeps candidate identifiers somewhere they
     * are not needed. The count is what tells you whether to go and look.
     *
     * @return array<string, int>
     */
    private function reasons(): array
    {
        return array_map('count', $this->skipped);
    }
}
