<?php

namespace App\Notifications;

use App\Models\NotarizationRequest;
use App\Models\Payment;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The notary's receipt for an offsite job, and the way back into it.
 *
 * A notary doing this is usually standing in front of a customer, on a phone,
 * having just paid. The two things they need are proof the money went somewhere
 * and a link straight to the editor — so both are at the top, and there is
 * nothing else in the email.
 */
class OffsiteJobReceipt extends Notification
{
    use Queueable;

    public function __construct(
        public NotarizationRequest $request,
        public Payment $payment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    private function money(): string
    {
        return NotarizationRequest::money((int) $this->payment->amount, $this->payment->currency ?: 'NGN');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->request->billableDocumentCount();

        return (new MailMessage)
            ->subject('Receipt — ' . $this->money() . ' for ' . $this->request->reference)
            ->greeting('Hello ' . ($notifiable->first_name ?: 'there') . ',')
            ->line('We have received ' . $this->money() . ' for ' . $count . ' '
                . ($count === 1 ? 'document' : 'documents') . ' on ' . $this->request->reference . '.')
            ->line('Your signature, stamp and seal are ready to place. Once you finalize, '
                . 'the sealed file is yours to download as many times as you need.')
            ->action('Notarize it now', route('notary.offsite.show', $this->request))
            ->line('Reference: ' . $this->request->reference
                . ' · Paid ' . now()->format('j M Y, g:i A'))
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'reference'  => $this->request->reference,
            'payment_id' => $this->payment->id,
            'amount'     => $this->payment->amount,
            'currency'   => $this->payment->currency ?: 'NGN',
            'type'       => 'offsite_receipt',
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'Paid — ready to seal',
            'body'  => $this->request->reference . ' is unlocked. Place your marks and finalize.',
            'url'   => route('notary.offsite.show', $this->request),
        ];
    }
}
