<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NotaryApplicationReviewed extends Notification
{
    use Queueable;

    public function __construct(
        public string $outcome,        // 'approved' | 'rejected'
        public ?string $notes = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting('Hello ' . ($notifiable->full_name ?? '') . ',');

        if ($this->outcome === 'approved') {
            return $mail
                ->subject('Your notary application is approved')
                ->line('Congratulations — your application to partner with Naija Virtual Notary has been approved.')
                ->line('Next, complete your profile: upload your signature, stamp, and seal, add your bank details, and set your pricing in both Naira and USD.')
                ->action('Complete your profile', route('notary.profile.edit'))
                ->salutation('— Naija Virtual Notary');
        }

        return $mail
            ->subject('Update on your notary application')
            ->line('Thank you for applying to partner with Naija Virtual Notary.')
            ->line('After review, we are unable to approve your application at this time.')
            ->when($this->notes, fn ($m) => $m->line('Note from our team: ' . $this->notes))
            ->line('You are welcome to reach out if you have questions.')
            ->salutation('— Naija Virtual Notary');
    }
}
