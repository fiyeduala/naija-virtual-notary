<?php

namespace App\Notifications;

use App\Models\NotarizationRequest;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the notary the FIRST time they hear about a request — only ever
 * after payment has cleared (the payment-first rule).
 */
class NotaryNewRequestNotification extends Notification
{
    use Queueable;

    public function __construct(public NotarizationRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = \App\Support\Settings::fallbackMinutes();

        return (new MailMessage)
            ->subject('New paid notarization request — ' . $this->request->reference)
            ->greeting('Hello ' . ($notifiable->full_name ?? '') . ',')
            ->line('You have a new paid notarization request (' . $this->request->reference . ').')
            ->line('Please review and accept it within ' . $minutes . ' minutes. If it is not completed, Naija Virtual Notary may complete it on your behalf under your signature, stamp and seal, so the client is not kept waiting — you remain the notary of record and are credited at your agreed rate.')
            ->action('View request', route('notary.requests.incoming'))
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'reference'  => $this->request->reference,
            'type'       => 'new_request',
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title'   => 'New request — ' . $this->request->reference,
            'body'    => 'A client has submitted a paid notarization request. Tap to review.',
            'icon'    => \App\Support\Branding::pushIconUrl(),
            'badge'   => \App\Support\Branding::pushIconUrl(),
            'url'     => route('notary.requests.incoming'),
            'vibrate' => [200, 100, 200],
        ];
    }
}
