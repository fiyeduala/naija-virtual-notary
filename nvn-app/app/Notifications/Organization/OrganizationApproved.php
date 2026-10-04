<?php

namespace App\Notifications\Organization;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The partnership is live: here is your link, and here is how to sign in.
 *
 * The temporary password is in this email because there is nowhere else to put
 * it — the body has no account to reset from until it has one. It is generated,
 * single-purpose and changed on first use, and the email says so.
 *
 * What the body earns is mentioned only under the commission arrangement. A
 * body that is simply charged a rate has no earnings to describe, and a
 * sentence explaining that it earns nothing would be a strange thing to open
 * a partnership with.
 */
class OrganizationApproved extends Notification
{
    use Queueable;

    public function __construct(
        public Organization $organization,
        public ?string $temporaryPassword = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $org = $this->organization;

        $mail = (new MailMessage)
            ->subject('Your Naija Virtual Notary partnership is live')
            ->greeting('Welcome, ' . $org->name . '.')
            ->line('Your partnership is approved. Anyone you send to the link below is notarized by our own '
                . 'notary public, at your agreed rate.')
            ->line('**Your link:** ' . $org->landingUrl())
            ->line('**Your code**, if someone needs to type it in: ' . $org->code)
            ->line('Your rate per notarization: ' . $org->displayDefaultPrice('NGN'));

        if ($org->earnsCommission()) {
            $mail->line('You earn ' . $org->commission_rate . '% of what your referrals pay, settled by '
                . 'bank transfer. You can see what is owed at any time in your portal.');
        }

        $mail->line('**Your portal** shows the work you have sent and its progress: ' . route('organization.login'));

        if ($this->temporaryPassword) {
            $mail->line('Sign in with ' . $org->email . ' and this one-time password: **'
                . $this->temporaryPassword . '**')
                ->line('Please change it once you are in.');
        }

        return $mail
            ->action('Open your portal', route('organization.login'))
            ->line('If anything above is wrong, reply to this email and we will correct it.');
    }
}
