<?php

namespace App\Notifications\Admin;

use App\Models\NotarizationRequest;
use App\Models\Payment;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A notary paid to seal a job of their own.
 *
 * Separate from RequestPaidNotification, which reads "a client paid and the
 * request is now live" — every clause of which is wrong here. This is revenue
 * with nobody waiting on it: no client raised it, no notary has to be chased,
 * and it shows up in none of the marketplace queues. The email is the only
 * place the desk would otherwise see it.
 */
class OffsiteJobPaidAlert extends Notification
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

    private function money(): string
    {
        return NotarizationRequest::money(
            (int) ($this->payment->amount ?? 0),
            $this->payment->currency ?? 'NGN',
        );
    }

    private function notaryName(): string
    {
        return $this->request->notary?->user?->full_name ?? 'a notary';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->request->billableDocumentCount();

        $mail = (new MailMessage)
            ->subject('Offsite notarization paid — ' . $this->request->reference)
            ->greeting('Hello,')
            ->line($this->notaryName() . ' has paid to seal ' . $count . ' '
                . ($count === 1 ? 'document' : 'documents') . ' from a job they took on themselves.');

        if ($this->payment) {
            $mail->line('Amount: ' . $this->money()
                . ' (' . ($this->payment->settlement_method ?? 'paystack') . ').');
        } else {
            $mail->line('No fee was charged — the offsite fee is currently set to zero.');
        }

        if ($use = $this->request->document_use) {
            $mail->line('They described it as: ' . $use);
        }

        return $mail
            ->line('Nothing is required of you. They seal it themselves and download it; '
                . 'this fee is platform revenue and is not part of any payout.')
            ->action('Open the job', route('filament.admin.resources.notarization-requests.view', ['record' => $this->request->id]))
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'reference'  => $this->request->reference,
            'payment_id' => $this->payment?->id,
            'amount'     => $this->payment?->amount,
            'currency'   => $this->payment?->currency ?? 'NGN',
            'documents'  => $this->request->billableDocumentCount(),
            'type'       => 'offsite_job_paid',
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'Offsite notarization — ' . ($this->payment ? $this->money() : 'free'),
            'body'  => $this->notaryName() . ' paid to seal '
                . $this->request->billableDocumentCount() . ' of their own documents.',
            'url'   => route('filament.admin.resources.notarization-requests.view', ['record' => $this->request->id]),
        ];
    }
}
