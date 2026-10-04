<?php

namespace App\Notifications\Organization;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The reset link for a partner body's portal login.
 *
 * Its own notification rather than Laravel's ResetPassword because that one
 * builds its URL from a static callback shared across the whole application —
 * pointing it at the organization portal would redirect every client's reset
 * link there too.
 *
 * It carries the organization explicitly: it is sent to the portal login
 * address on demand, not to the body's contact, so the notifiable it arrives
 * with is an anonymous address and knows nothing about the body.
 */
class OrganizationPasswordReset extends Notification
{
    use Queueable;

    public function __construct(
        private Organization $organization,
        private string $token,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('organization.password.reset', [
            'token' => $this->token,
            'email' => $this->organization->email,
        ]);

        return (new MailMessage)
            ->subject('Reset your ' . config('app.name') . ' partner portal password')
            ->greeting('Hello ' . $this->organization->name)
            ->line('Somebody asked to reset the password for your partner portal.')
            ->action('Choose a new password', $url)
            ->line('The link expires in 60 minutes. If this was not you, nothing has changed and you can ignore this email.');
    }
}
