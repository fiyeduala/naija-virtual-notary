<?php

namespace App\Notifications;

use App\Models\NotarizationRequest;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the client when their request is accepted (by the notary or via fallback). */
class RequestAcceptedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public NotarizationRequest $request,
        public bool $viaFallback = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): array
    {
        $when = optional($this->request->session)->scheduled_start_at;

        return [
            'title' => 'Notarization confirmed — ' . $this->request->reference,
            'body'  => $when
                ? 'Scheduled for ' . $when->format('l, j M · g:i A') . '.'
                : 'Your request has been accepted.',
            'url'   => route('client.dashboard'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = optional($this->request->session)->scheduled_start_at;

        $mail = (new MailMessage)
            ->subject('Your notarization is confirmed — ' . $this->request->reference)
            ->greeting('Hello ' . ($notifiable->full_name ?? '') . ',')
            ->line('Good news — your notarization request ' . $this->request->reference . ' is confirmed.');

        if ($when) {
            $mail->line('Scheduled for: ' . $when->format('l, j M Y · g:i A'));
        }

        // The client booked a named notary and their seal is what goes on the
        // document, whoever at the platform does the keystrokes. Saying "we
        // took over" here would contradict the certificate they receive.
        if ($notary = $this->request->notary?->user?->full_name) {
            $mail->line('Your notary: ' . $notary . '.');
        }

        if ($this->viaFallback) {
            $mail->line('Naija Virtual Notary is assisting with the session so there is no delay. Your document is notarized under your notary\'s signature, stamp and seal exactly as booked.');
        }

        return $mail
            ->action('View your request', route('client.dashboard'))
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id'   => $this->request->id,
            'reference'    => $this->request->reference,
            'type'         => 'request_accepted',
            'via_fallback' => $this->viaFallback,
        ];
    }
}
