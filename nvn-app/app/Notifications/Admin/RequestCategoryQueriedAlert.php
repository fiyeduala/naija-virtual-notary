<?php

namespace App\Notifications\Admin;

use App\Models\NotarizationRequest;
use App\Models\NotaryService;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the desk: a partner notary has sent a paid request back to the client.
 *
 * Only fires when somebody other than an admin raised the query, and it exists
 * because this is the one thing a partner can do that stops a paid job without
 * cancelling it. If the reason is wrong, or the recommendation is a service
 * that happens to cost more, the admin is the only person in a position to
 * notice — so they get told at the moment it happens, not when the client
 * complains.
 */
class RequestCategoryQueriedAlert extends Notification
{
    use Queueable;

    public function __construct(
        public NotarizationRequest $request,
        public User $raisedBy,
        public string $reason,
        public ?NotaryService $suggested = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    private function adminUrl(): string
    {
        return route('filament.admin.resources.notarization-requests.view', ['record' => $this->request->id]);
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => $this->request->reference . ' sent back — wrong category',
            'body'  => ($this->raisedBy->full_name ?? 'A notary') . ': ' . \Illuminate\Support\Str::limit($this->reason, 90),
            'url'   => $this->adminUrl(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $currency = $this->request->currency ?: 'NGN';

        $mail = (new MailMessage)
            ->subject('Wrong category raised on ' . $this->request->reference)
            ->greeting('Hello,')
            ->line(($this->raisedBy->full_name ?? 'A notary')
                . ' has queried the category on ' . $this->request->reference
                . ', booked by ' . ($this->request->client?->full_name ?? 'a client') . '.')
            ->line('**Booked as:** ' . ($this->request->service?->service_type ?? 'nothing yet')
                . ' — ' . $this->request->displayFeeOrPending())
            ->line('**Reason given:** ' . $this->reason);

        if ($this->suggested) {
            $mail->line('**Recommended instead:** ' . $this->suggested->service_type
                . ' — ' . $this->suggested->displayPrice($currency));
        }

        return $mail
            ->line('The client has been asked to re-pick and the job is paused until they do. '
                . 'Their payment has not been touched. Withdraw the query from the request page '
                . 'if this is not right.')
            ->action('Open the request', $this->adminUrl())
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'reference'  => $this->request->reference,
            'type'       => 'request_category_queried_alert',
            'raised_by'  => $this->raisedBy->id,
            'reason'     => $this->reason,
            'suggested'  => $this->suggested?->service_type,
        ];
    }
}
