<?php

namespace App\Notifications\Admin;

use App\Models\NotarizationRequest;
use App\Models\Payment;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Money landed and a request went live.
 *
 * This one does get an email as well as a push. It is the revenue event and the
 * record an admin goes back and searches for months later, which is exactly
 * what an inbox is good at and a phone banner is not.
 */
class RequestPaidNotification extends Notification
{
    use Queueable;

    public function __construct(
        public NotarizationRequest $request,
        public ?Payment $payment = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    /** Minor units to something a person reads, same convention as the ledger. */
    private function money(): ?string
    {
        if (! $this->payment) {
            return null;
        }

        return ($this->payment->currency === 'USD' ? '$' : '₦')
            . number_format(((int) $this->payment->amount) / 100, 2);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = $this->money();
        $notary = $this->request->notary?->user?->full_name;

        $mail = (new MailMessage)
            ->subject('Payment received — ' . $this->request->reference)
            ->greeting('Hello,')
            ->line('Request ' . $this->request->reference . ' has been paid for and is now live.');

        if ($amount) {
            $mail->line('Amount: ' . $amount . ' (' . ($this->payment->settlement_method ?? 'paystack') . ').');
        }

        if ($client = $this->request->client?->full_name) {
            $mail->line('Client: ' . $client . '.');
        }

        if ($notary) {
            $mail->line('Notary: ' . $notary . '.');
        }

        return $mail
            ->action('Open the request', route('filament.admin.resources.notarization-requests.view', ['record' => $this->request->id]))
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'reference'  => $this->request->reference,
            'payment_id' => $this->payment?->id,
            'amount'     => $this->payment?->amount,
            'currency'   => $this->payment?->currency,
            'type'       => 'request_paid',
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        $amount = $this->money();

        return [
            'title' => 'Payment received' . ($amount ? ' — ' . $amount : ''),
            'body'  => $this->request->reference . ' is paid and live'
                . ($this->request->client?->full_name ? ' for ' . $this->request->client->full_name : '') . '.',
            'url'   => route('filament.admin.resources.notarization-requests.view', ['record' => $this->request->id]),
        ];
    }
}
