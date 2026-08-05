<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The account-verification code email. Sent over native cPanel SMTP.
 *
 * Implements ShouldQueue indirectly via the database queue — but kept simple
 * here; if you want it queued, add `implements ShouldQueue` and the queue will
 * pick it up via the per-minute cron.
 */
class EmailOtpNotification extends Notification
{
    use Queueable;

    public function __construct(public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Naija Virtual Notary verification code')
            ->greeting('Hello ' . ($notifiable->full_name ?? '') . ',')
            ->line('Use the code below to verify your account:')
            ->line('**' . $this->code . '**')
            ->line('This code expires in ' . \App\Services\OtpService::EXPIRY_MINUTES . ' minutes.')
            ->line('If you did not create an account, you can ignore this email.')
            ->salutation('— Naija Virtual Notary');
    }
}
