<?php

namespace App\Notifications\Organization;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A short, plain decline.
 *
 * The review note goes in only if an admin wrote one, and it is quoted as
 * ours rather than dressed up as a policy. A body declined for something
 * fixable — the wrong paperwork, a name that does not match the register —
 * should be able to read the reason and come back.
 */
class OrganizationDeclined extends Notification
{
    use Queueable;

    public function __construct(public Organization $organization) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('About your Naija Virtual Notary partnership application')
            ->greeting('Dear ' . ($this->organization->contact_name ?: $this->organization->name) . ',')
            ->line('Thank you for applying to partner with Naija Virtual Notary. We are not able to take '
                . 'your application forward at this time.');

        if ($note = trim((string) $this->organization->review_note)) {
            $mail->line('What we noted: ' . $note);
        }

        return $mail->line('You are welcome to apply again if your circumstances change, and you can still '
            . 'use our ordinary notarization service at any time.');
    }
}
