<?php

namespace App\Notifications;

use App\Notifications\Channels\WebPushChannel;
use App\Support\Branding;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The answer to a listing request.
 *
 * A decline is the more important of the two messages and the harder one to
 * write: the notary has paid, done everything asked, and is being told no by a
 * platform they cannot argue with. So it says exactly what is wrong, says the
 * decision is not permanent, and sends them straight back to the form — a
 * refusal with no route out of it is what makes people ask for a refund.
 */
class NotaryListingReviewed extends Notification
{
    use Queueable;

    public function __construct(
        public bool $listed,
        public ?string $notes = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $greeting = 'Hello ' . ($notifiable->full_name ?: 'there') . ',';

        if ($this->listed) {
            return (new MailMessage)
                ->subject('You are live on Naija Virtual Notary')
                ->greeting($greeting)
                ->line('We have checked your signature, stamp and seal, and your profile is now '
                    . 'listed in the marketplace. Clients can find you and book you from today.')
                ->line('Requests arrive by email and on your dashboard. The sooner you accept one, '
                    . 'the better it reads to the client who is waiting.')
                ->action('Open your dashboard', route('notary.dashboard'))
                ->salutation('— Naija Virtual Notary');
        }

        $mail = (new MailMessage)
            ->subject('About your listing — one thing to fix first')
            ->greeting($greeting)
            ->line('Thank you for completing your profile. Before we put you in front of clients we '
                . 'check every notary\'s signature, stamp and seal by hand, and yours is not ready yet.');

        if (filled($this->notes)) {
            $mail->line('**' . $this->notes . '**');
        }

        return $mail
            ->line('Upload a replacement on your profile page and send it back to us — there is no '
                . 'limit on how many times you can do this, and nothing else about your account is '
                . 'affected. Your membership year is not being spent while you wait.')
            ->action('Fix it and resubmit', route('notary.profile.edit'))
            ->line('If you are not sure what we are asking for, reply to this email and we will '
                . 'walk you through it.')
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'   => $this->listed ? 'notary_listed' : 'notary_listing_declined',
            'listed' => $this->listed,
            'notes'  => $this->notes,
            'url'    => $this->listed ? route('notary.dashboard') : route('notary.profile.edit'),
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => $this->listed ? 'You are live in the marketplace' : 'Your listing needs one fix',
            'body'  => $this->listed
                ? 'Clients can now find and book you.'
                : ($this->notes ?: 'Your signature, stamp or seal needs replacing.'),
            'icon'  => Branding::pushIconUrl(),
            'badge' => Branding::pushIconUrl(),
            'url'   => $this->listed ? route('notary.dashboard') : route('notary.profile.edit'),
        ];
    }
}
