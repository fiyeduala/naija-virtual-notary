<?php

namespace App\Notifications\Admin;

use App\Models\Organization;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A body has asked to partner with the platform.
 *
 * This one does get a mail channel, unlike a client signing up. A partnership
 * application is a negotiation waiting on a reply from a person, there will be
 * a handful of them rather than a stream, and a body left waiting a week
 * because nobody opened the panel is a body that goes elsewhere.
 */
class OrganizationAppliedNotification extends Notification
{
    use Queueable;

    public function __construct(public Organization $organization) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        $org = $this->organization;

        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Partnership application — ' . $org->name)
            ->greeting('A body has applied to partner with us.')
            ->line($org->name . ($org->sector ? ' (' . $org->sector . ')' : ''))
            ->line('Contact: ' . $org->contact_name . ', ' . $org->contact_role)
            ->line($org->contact_email . ' · ' . $org->phone)
            ->line('Expected volume: ' . ($org->expected_volume ?: 'not stated'))
            ->action('Review the application', route('filament.admin.resources.organizations.edit', $org->id))
            ->line('Nothing is live until you set their arrangement and price and approve it.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'organization_id' => $this->organization->id,
            'name'            => $this->organization->name,
            'contact_email'   => $this->organization->contact_email,
            'sector'          => $this->organization->sector,
            'type'            => 'organization_applied',
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'Partnership application',
            'body'  => $this->organization->name . ' — ' . $this->organization->contact_name,
            'url'   => route('filament.admin.resources.organizations.index'),
        ];
    }
}
