<?php

namespace App\Mail;

use App\Models\EmailCampaignRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * An email composed by an admin in the panel.
 *
 * The body is admin-authored HTML. It is inserted into the template as-is and
 * is NEVER rendered as Blade — placeholders are substituted by plain string
 * replacement, because compiling admin input as a template would turn the
 * compose box into arbitrary PHP execution.
 */
class AdminBroadcastMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EmailCampaignRecipient $recipient) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: static::fill($this->recipient->campaign->subject, $this->recipient),
        );
    }

    public function content(): Content
    {
        $campaign = $this->recipient->campaign;

        return new Content(
            view: 'emails.admin-broadcast',
            with: [
                'bodyHtml'    => static::fill($campaign->body, $this->recipient),
                // Filled, because the template puts it in <title> and a raw
                // "{{ first_name }}" there is exactly the kind of thing that
                // shows up in a preview pane.
                'subject'     => static::fill($campaign->subject, $this->recipient),
                'campaign'    => $campaign,
                'recipient'   => $this->recipient,
                // Only a broadcast carries an opt-out. A one-to-one message
                // about someone's own request is correspondence, and offering
                // to unsubscribe from it makes no sense.
                'unsubscribe' => $campaign->audience === 'individual'
                    ? null
                    : static::unsubscribeUrl($this->recipient),
            ],
        );
    }

    /**
     * Substitute the placeholders the compose screen advertises. Anything the
     * admin typed that is not one of these is left alone.
     */
    public static function fill(string $text, EmailCampaignRecipient $recipient): string
    {
        $name  = trim((string) $recipient->name);
        $first = $name === '' ? '' : explode(' ', $name)[0];

        return str_replace(
            ['{{ name }}', '{{ first_name }}', '{{ email }}'],
            [$name, $first, (string) $recipient->email],
            $text,
        );
    }

    /** A signed link, so nobody can unsubscribe anyone else by editing a URL. */
    public static function unsubscribeUrl(EmailCampaignRecipient $recipient): ?string
    {
        if (! $recipient->user_id) {
            return null;
        }

        return URL::signedRoute('email.unsubscribe', ['user' => $recipient->user_id]);
    }
}
