<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\NotarizationRequest;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Notifies a thread participant of a new message (email + in-app). */
class NewMessageNotification extends Notification
{
    use Queueable;

    public function __construct(
        public NotarizationRequest $request,
        public Message $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'New message — ' . $this->request->reference,
            'body'  => (string) str($this->message->body)->limit(120),
            'url'   => route('messages.show', $this->request),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Route recipients to the thread view appropriate to their role.
        $url = $notifiable->isClient()
            ? route('messages.show', $this->request)
            : route('messages.show', $this->request);

        return (new MailMessage)
            ->subject('New message about ' . $this->request->reference)
            ->greeting('Hello ' . ($notifiable->full_name ?? '') . ',')
            ->line('You have a new message regarding notarization request ' . $this->request->reference . ':')
            ->line('"' . str($this->message->body)->limit(140) . '"')
            ->action('View and reply', $url)
            ->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'reference'  => $this->request->reference,
            'message_id' => $this->message->id,
            'type'       => 'new_message',
        ];
    }
}
