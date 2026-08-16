<?php

namespace App\Notifications;

use App\Models\NotarizationRequest;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the admin / system-native notary when a request falls back to them. */
class FallbackAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public NotarizationRequest $request,
        public string $trigger, // 'declined' | 'admin_took_over' | 'system_native_selected'
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'Request on your desk — ' . $this->request->reference,
            'body'  => $this->trigger === 'system_native_selected'
                ? 'A client booked the platform notary directly.'
                : 'This request has fallen back to you. Tap to open it.',
            'url'   => route('filament.admin.resources.notarization-requests.view', ['record' => $this->request->id]),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $direct = $this->trigger === 'system_native_selected';

        $reason = match ($this->trigger) {
            'declined'               => 'the assigned notary declined',
            'system_native_selected' => 'the client booked the platform notary directly',
            default                  => 'you took it over',
        };

        $mail = (new MailMessage)
            ->subject(($direct ? 'Notarization assigned' : 'Notarization on a partner\'s behalf') . ' — ' . $this->request->reference)
            ->greeting('Hello,')
            ->line('Request ' . $this->request->reference . ' is on your desk because ' . $reason . '.');

        if (! $direct) {
            $notary = $this->request->notary?->user?->full_name ?? 'the assigned notary';

            $mail->line('Notarize it under ' . $notary . '\'s signature, stamp and seal, at the price they set — they remain the notary of record on the client\'s document.');
        }

        // The admin desk works out of the admin panel — notary.requests.show is
        // scoped to the assigned partner notary and would 403 here.
        return $mail
            ->action('Open request', route('filament.admin.resources.notarization-requests.view', ['record' => $this->request->id]))
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'reference'  => $this->request->reference,
            'type'       => 'fallback_assigned',
            'trigger'    => $this->trigger,
        ];
    }
}
