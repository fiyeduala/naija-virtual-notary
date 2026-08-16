<?php

namespace App\Notifications;

use App\Models\NotaryProfile;
use App\Notifications\Channels\WebPushChannel;
use App\Support\Branding;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks an approved notary for the marks they still have not uploaded.
 *
 * The approval email already asks. This is what happens when that one email is
 * missed, and it is deliberately specific: it names the marks that are actually
 * absent rather than repeating the whole list, because "upload your assets" to
 * someone who already uploaded two of three reads as though nothing arrived.
 *
 * Sent on a spacing rather than continuously — see SendAssetReminders.
 */
class NotaryAssetsReminder extends Notification
{
    use Queueable;

    public function __construct(public NotaryProfile $profile) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $missing = $this->profile->missingSealingAssets();
        $count   = count($missing);

        $mail = (new MailMessage)
            ->subject($count === 3
                ? 'Upload your signature, stamp and seal to start taking work'
                : 'One more thing before you can be booked on Naija Virtual Notary')
            ->greeting('Hello ' . ($notifiable->full_name ?: 'there') . ',')
            ->line('Your notary account is approved — but we do not yet hold everything we need to '
                . 'put your name on a document, so you are not receiving bookings.');

        $mail->line($count === 3
            ? '**We still need all three of your marks: your signature, your stamp and your seal.**'
            : '**We still need your ' . static::readable($missing) . '.**');

        // The upload form takes all three together and requires every one of
        // them, so telling a notary who is missing only the seal to "upload
        // your seal" walks them into a form that refuses to submit.
        $mail->line($count === 3
            ? 'Upload them on your profile page. It asks for all three at once, along with the '
                . 'initials you use when initialling a page.'
            : 'Upload it on your profile page. The form asks for all three marks together, so '
                . 'please attach your signature, stamp and seal in the same go — including the '
                . 'ones we already hold. It also asks for the initials you use when initialling '
                . 'a page.');

        return $mail
            ->line('PNG or JPG, up to 4MB each. A clear photo or a scan on white paper is fine — '
                . 'you do not need a designer. A PNG with a transparent background is ideal if you '
                . 'happen to have one.')
            ->action($count === 3 ? 'Upload them now' : 'Upload it now', route('notary.profile.edit'))
            ->line('Once all three are on file you can switch your listing on, and clients will be '
                . 'able to find and book you straight away.')
            ->line('If you are stuck, or you would rather send the files to us to upload for you, '
                . 'just reply to this email.')
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'notary_assets_missing',
            'profile_id' => $this->profile->id,
            'missing'    => $this->profile->missingSealingAssets(),
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        $missing = $this->profile->missingSealingAssets();

        return [
            'title' => 'Finish setting up your notary account',
            'body'  => 'We still need your ' . static::readable($missing) . ' before you can be booked.',
            'icon'  => Branding::pushIconUrl(),
            'badge' => Branding::pushIconUrl(),
            'url'   => route('notary.profile.edit'),
        ];
    }

    /** "signature, stamp and seal" — not the array's own comma-separated shape. */
    private static function readable(array $missing): string
    {
        if (count($missing) <= 1) {
            return $missing[0] ?? 'sealing marks';
        }

        $last = array_pop($missing);

        return implode(', ', $missing) . ' and ' . $last;
    }
}
