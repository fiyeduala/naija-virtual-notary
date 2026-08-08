<?php

namespace App\Notifications;

use App\Models\NotaryProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a partner their yearly membership is ending, or has ended.
 *
 * Sent on a small number of fixed days rather than continuously — see
 * SendMembershipReminders. Someone whose fee is due should hear about it a
 * handful of times, not every morning until they pay.
 */
class MembershipRenewalNotification extends Notification
{
    use Queueable;

    public function __construct(public NotaryProfile $profile) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expires = $this->profile->membership_expires_at;
        $lapsed  = $this->profile->membershipLapsed();
        $days    = $this->profile->membershipDaysLeft();

        $mail = (new MailMessage)
            ->subject($lapsed
                ? 'Your Naija Virtual Notary membership has ended'
                : 'Your Naija Virtual Notary membership ends in ' . $days . ' days')
            ->greeting('Hello ' . ($notifiable->full_name ?: 'there') . ',');

        if ($lapsed) {
            $mail->line('Your partner membership ended on ' . $expires->format('j F Y') . '.')
                 ->line('You are no longer listed in the marketplace, so new clients cannot find or book you. '
                     . 'Nothing else has changed — your profile, your credentials, your seal and your '
                     . 'completed work are all exactly where you left them.')
                 ->line('Renewing puts you straight back on the list.');
        } else {
            $mail->line('Your partner membership runs until ' . $expires->format('j F Y')
                    . ' — that is ' . $days . ' ' . str('day')->plural($days) . ' from today.')
                 ->line('Renewing keeps you listed in the marketplace without a break. '
                     . 'Paying early does not cost you anything: the new year is added onto the date '
                     . 'you already hold rather than starting from today.');
        }

        return $mail
            ->action('Renew your membership', route('notary.onboarding.fee'))
            ->line('If you would rather pay by bank transfer, reply to this email and we will record it for you.')
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'membership_renewal',
            'profile_id' => $this->profile->id,
            'expires_at' => $this->profile->membership_expires_at?->toDateString(),
            'lapsed'     => $this->profile->membershipLapsed(),
        ];
    }
}
