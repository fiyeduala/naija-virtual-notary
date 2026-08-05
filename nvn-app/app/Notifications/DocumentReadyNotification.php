<?php

namespace App\Notifications;

use App\Models\NotarizationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the client when their notarized document is ready in the dashboard. */
class DocumentReadyNotification extends Notification
{
    use Queueable;

    public function __construct(public NotarizationRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your notarized document is ready — ' . $this->request->reference)
            ->greeting('Hello ' . ($notifiable->full_name ?? '') . ',')
            ->line('Your document for request ' . $this->request->reference . ' has been notarized and is ready in your dashboard.')
            ->action('Download your document', route('client.documents.download', $this->request));

        if ($this->request->hard_copy_requested) {
            $mail->line('You requested a hard copy — we\'ll arrange delivery to your address.');
        }

        return $mail->salutation('— Naija Virtual Notary');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'reference'  => $this->request->reference,
            'type'       => 'document_ready',
        ];
    }
}
