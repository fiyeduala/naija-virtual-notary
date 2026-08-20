<?php

namespace App\Notifications\Admin;

use App\Models\NotaryProfile;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A notary has put their e-signature, stamp and seal on file.
 *
 * The moment the images arrive is the moment they can be judged, and it comes
 * before the listing request — sometimes days before, and sometimes instead of
 * it, when a notary uploads and then never presses the button. Waiting for the
 * request meant the wrong seal sat unlooked-at until a client had already
 * booked. This is the earlier warning.
 *
 * `$replacement` separates "somebody new has arrived" from "somebody we told to
 * fix it has fixed it" — the second is a reply and should read like one.
 */
class NotaryAssetsUploaded extends Notification
{
    use Queueable;

    public function __construct(
        public NotaryProfile $profile,
        public bool $replacement = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->profile->user?->full_name ?? 'A notary';

        $mail = (new MailMessage)
            ->subject($this->replacement
                ? $name . ' has replaced their signature, stamp or seal'
                : $name . ' has uploaded their e-signature and identity marks')
            ->greeting('Hello,')
            ->line($this->replacement
                ? $name . ' has uploaded a fresh set of marks, replacing the ones we held.'
                : $name . ' has uploaded their signature, stamp and seal.');

        if (filled($this->profile->scn)) {
            $mail->line('SCN on file: **' . $this->profile->scn . '**');
        }

        return $mail
            // The point of the email. Nothing downstream can do this check:
            // the system can see that three files exist and nothing more.
            ->line('**Open the images and look at them.** A wrong or unreadable seal passes every '
                . 'check the platform can run, and only stops being a problem before a client books.')
            ->action('Look at their marks', route(
                'filament.admin.resources.notary-profiles.view',
                ['record' => $this->profile->id],
            ))
            ->line($this->profile->public_listing_enabled
                ? 'This notary is already listed, so these marks are live on documents from now on. '
                    . 'If they are wrong, unlist them.'
                : 'They are not listed yet. You will get a second email when they ask to be.')
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'notary_assets_uploaded',
            'profile_id'  => $this->profile->id,
            'name'        => $this->profile->user?->full_name,
            'replacement' => $this->replacement,
            'listed'      => (bool) $this->profile->public_listing_enabled,
            'url'         => route('filament.admin.resources.notary-profiles.view', ['record' => $this->profile->id]),
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => $this->replacement ? 'Marks replaced' : 'New notary marks uploaded',
            'body'  => ($this->profile->user?->full_name ?? 'A notary')
                . ' — signature, stamp and seal are on file. Check the images.',
            'url'   => route('filament.admin.resources.notary-profiles.view', ['record' => $this->profile->id]),
        ];
    }
}
