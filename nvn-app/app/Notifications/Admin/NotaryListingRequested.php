<?php

namespace App\Notifications\Admin;

use App\Models\NotaryProfile;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A notary has finished their profile and wants to be in the marketplace.
 *
 * Mailed, unlike a signup, because nothing happens until somebody acts: the
 * partner has paid for a year of being findable and is not findable, and the
 * only thing standing between them and a booking is a human looking at three
 * images. A queue nobody is told about is a queue nobody empties.
 */
class NotaryListingRequested extends Notification
{
    use Queueable;

    public function __construct(public NotaryProfile $profile) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->profile->user?->full_name ?? 'A notary';

        return (new MailMessage)
            ->subject($name . ' is asking to be listed')
            ->greeting('Hello,')
            ->line($name . ' has completed their profile and asked to appear in the marketplace.')
            // Said plainly, because this is the entire reason the request
            // exists. The completeness checks already passed; they cannot tell
            // a real seal from a screenshot of somebody else's.
            ->line('**Open their signature, stamp and seal and look at the images before you list them.** '
                . 'Everything the system can check has already passed — whether the marks are genuine '
                . 'and legible is the part only you can check.')
            ->action('Review their marks', $this->url())
            ->line('If something is wrong, decline it with a reason and they will be told what to replace.')
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'notary_listing_requested',
            'profile_id' => $this->profile->id,
            'name'       => $this->profile->user?->full_name,
            'url'        => $this->url(),
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'Listing request',
            'body'  => ($this->profile->user?->full_name ?? 'A notary')
                . ' wants to be listed — check their stamp and seal.',
            'url'   => $this->url(),
        ];
    }

    private function url(): string
    {
        return route('filament.admin.resources.notary-profiles.view', ['record' => $this->profile->id]);
    }
}
