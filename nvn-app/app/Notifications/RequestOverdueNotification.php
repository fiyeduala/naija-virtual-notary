<?php

namespace App\Notifications;

use App\Models\NotarizationRequest;
use App\Notifications\Channels\WebPushChannel;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the admin when a paid request passes its response window unanswered.
 *
 * This is a heads-up, not a handover. The request is still assigned to the
 * notary the client picked; the admin decides whether to take it over.
 */
class RequestOverdueNotification extends Notification
{
    use Queueable;

    public function __construct(public NotarizationRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'Awaiting a response — ' . $this->request->reference,
            'body'  => ($this->request->notary?->user?->full_name ?? 'The assigned notary')
                . ' has not answered a paid request.',
            'url'   => route('notary.dashboard'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $notary  = $this->request->notary?->user?->full_name ?? 'the assigned notary';
        $minutes = Settings::fallbackMinutes();

        return (new MailMessage)
            ->subject('Request awaiting a response — ' . $this->request->reference)
            ->greeting('Hello,')
            ->line($this->request->reference . ' has been paid for ' . $minutes . '+ minutes and ' . $notary . ' has not responded.')
            ->line('It is still assigned to them. You can take it over from your notarization desk and complete it under their name and seal, or leave it with them a while longer.')
            ->action('Open the desk', route('notary.dashboard'))
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'reference'  => $this->request->reference,
            'type'       => 'request_overdue',
            'notary'     => $this->request->notary?->user?->full_name,
        ];
    }
}
