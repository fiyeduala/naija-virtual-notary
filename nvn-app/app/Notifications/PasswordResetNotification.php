<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * The password-reset email. Mirrors EmailOtpNotification's tone so the two
 * account emails read as coming from the same product.
 *
 * The link carries the broker token plus the email, because the reset form
 * needs both to validate.
 */
class PasswordResetNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $minutes = Config::get('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your Naija Virtual Notary password')
            ->greeting('Hello ' . ($notifiable->full_name ?? '') . ',')
            ->line('We received a request to reset the password on your account.')
            ->action('Choose a new password', $url)
            ->line('This link expires in ' . $minutes . ' minutes.')
            ->line('If you did not request a reset, no action is needed — your password stays as it is.')
            ->salutation('— Naija Virtual Notary');
    }
}
