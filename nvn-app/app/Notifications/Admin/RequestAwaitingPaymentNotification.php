<?php

namespace App\Notifications\Admin;

use App\Models\NotarizationRequest;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A request was filled in and uploaded, but not paid for.
 *
 * This is the top of the funnel leaking, so it is worth seeing quickly — and
 * worth seeing on a phone rather than in an inbox, because the useful response
 * is to call the client, not to file the mail. Push and in-app only.
 *
 * Nothing has been assigned to a notary at this point: the payment-first rule
 * means the request is still a draft and no partner has been told it exists.
 */
class RequestAwaitingPaymentNotification extends Notification
{
    use Queueable;

    public function __construct(public NotarizationRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'reference'  => $this->request->reference,
            'client'     => $this->request->client?->full_name,
            'type'       => 'request_awaiting_payment',
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'Unpaid request — ' . $this->request->reference,
            'body'  => ($this->request->client?->full_name ?? 'A client')
                . ' submitted documents but has not paid yet.',
            'url'   => route('filament.admin.resources.notarization-requests.view', ['record' => $this->request->id]),
        ];
    }
}
