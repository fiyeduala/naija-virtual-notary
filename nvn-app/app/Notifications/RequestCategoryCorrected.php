<?php

namespace App\Notifications;

use App\Models\NotarizationRequest;
use App\Models\NotaryService;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the desk: the client has answered the category query.
 *
 * Goes to the assigned notary and, when the request is not already theirs, to
 * the admins. The balance is the headline, not a footnote: it is the whole
 * difference between "carry on" and "do not touch this yet".
 */
class RequestCategoryCorrected extends Notification
{
    use Queueable;

    public function __construct(
        public NotarizationRequest $request,
        public NotaryService $chosen,
        public int $balanceMinor,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    private function balance(): string
    {
        return NotarizationRequest::money($this->balanceMinor, $this->request->currency ?: 'NGN');
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => $this->request->reference . ' — category corrected',
            'body'  => $this->balanceMinor > 0
                ? 'Now ' . $this->chosen->service_type . '. Waiting on ' . $this->balance() . ' before it restarts.'
                : 'Now ' . $this->chosen->service_type . '. Nothing outstanding — it is back on your desk.',
            'url'   => route('notary.requests.show', $this->request),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->request->reference . ' is now filed as ' . $this->chosen->service_type)
            ->greeting('Hello ' . ($notifiable->full_name ?? '') . ',')
            ->line('The client has answered the category query on ' . $this->request->reference . '.')
            ->line('**Category:** ' . $this->chosen->service_type)
            ->line('**Paid so far:** ' . NotarizationRequest::money(
                $this->request->amountPaidMinor(), $this->request->currency ?: 'NGN',
            ) . ' of ' . $this->request->displayFee());

        if ($this->balanceMinor > 0) {
            $mail->line('**Outstanding: ' . $this->balance() . '.** The job stays paused until '
                . 'that clears — you will hear from us again when it does. Nothing to do for now.');
        } else {
            $mail->line('There is nothing outstanding. The request is back on your desk and the '
                . 'response clock has restarted.');

            if ($this->request->overpaidMinor() > 0) {
                $mail->line('Note: the corrected category costs less than what was paid, leaving '
                    . $this->request->displayOverpaid() . ' in credit. The admin desk will settle that '
                    . 'with the client — it does not affect your fee for this job.');
            }
        }

        return $mail
            ->action('Open the request', route('notary.requests.show', $this->request))
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'reference'  => $this->request->reference,
            'type'       => 'request_category_corrected',
            'service'    => $this->chosen->service_type,
            'balance'    => $this->balanceMinor,
        ];
    }
}
