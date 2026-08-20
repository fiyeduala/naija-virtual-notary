<?php

namespace App\Notifications;

use App\Models\NotarizationRequest;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the client: the category you chose does not match the document you sent.
 *
 * The one thing this email has to do before anything else is stop the reader
 * panicking about their money. "Your payment is safe" comes before the reason,
 * before the recommendation, before the link — because a client who has paid
 * and then hears from us that something is wrong assumes the worst, and the
 * subject line is as far as a lot of people read.
 */
class RequestCategoryQueried extends Notification
{
    use Queueable;

    public function __construct(public NotarizationRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'Check the category on ' . $this->request->reference,
            'body'  => 'Your payment is safe — we just need the right category before we notarize.',
            'url'   => route('client.request.category.show', $this->request),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $suggested = $this->request->categorySuggestedService;
        $currency  = $this->request->currency ?: 'NGN';

        $mail = (new MailMessage)
            ->subject('One thing to fix on ' . $this->request->reference . ' — your payment is safe')
            ->greeting('Hello ' . ($notifiable->full_name ?? '') . ',')
            ->line('Your notarization request ' . $this->request->reference
                . ' is booked under a category that does not match the document you sent us.')
            ->line('**Nothing has been refunded and nothing has been lost.** Everything you have '
                . 'already paid stays on this request. All you need to do is choose the right '
                . 'category — and if it costs more, pay only the difference.');

        if ($reason = $this->request->category_query_reason) {
            $mail->line('**What we found:** ' . $reason);
        }

        if ($suggested) {
            $mail->line('**We think this is:** ' . $suggested->service_type
                . ' — ' . $suggested->displayPrice($currency)
                . '. You do not have to take our word for it; the full price list is on the page below.');
        }

        return $mail
            ->action('Choose the right category', route('client.request.category.show', $this->request))
            ->line('Your notary is holding the job until you have chosen.')
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'reference'  => $this->request->reference,
            'type'       => 'request_category_queried',
            'reason'     => $this->request->category_query_reason,
            'suggested'  => $this->request->categorySuggestedService?->service_type,
        ];
    }
}
