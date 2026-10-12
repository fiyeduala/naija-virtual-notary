<?php

namespace App\Mail;

use App\Models\RecruitmentMessage;
use App\Support\RecruitmentTemplates;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Every email a candidate receives, from one class.
 *
 * The wording comes from App\Support\RecruitmentTemplates, which an
 * administrator may edit; the structure, branding and escaping come from the
 * view and cannot be edited. One mailable rather than ten means the preview
 * screen in the panel renders exactly what a candidate will receive, for every
 * template, without ten preview routes.
 *
 * Notice what is never attached: a candidate's own documents. An email carries
 * a link to a page that checks who is asking. A calendar file is the single
 * exception, and it contains only a time, a title and a link.
 */
class CandidateMail extends Mailable
{
    public function __construct(
        public RecruitmentMessage $message,
        /** Set when rendering a preview, so no link has to resolve. */
        public bool $preview = false,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = RecruitmentTemplates::replyTo();

        return new Envelope(
            subject: $this->rendered()['subject'],
            replyTo: $replyTo ? [new Address($replyTo)] : [],
        );
    }

    public function content(): Content
    {
        $rendered = $this->rendered();
        $payload  = (array) ($this->message->payload ?? []);

        return new Content(
            view: 'emails.recruitment.candidate',
            with: [
                'subject'    => $rendered['subject'],
                'paragraphs' => $rendered['paragraphs'],
                'actionText' => $rendered['action'],
                'actionUrl'  => $this->preview ? null : ($payload['action_url'] ?? null),
                'footnote'   => $payload['footnote'] ?? null,
                'replyTo'    => RecruitmentTemplates::replyTo(),
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $payload = (array) ($this->message->payload ?? []);
        $ics     = $payload['ics'] ?? null;

        if ($this->preview || ! is_string($ics) || $ics === '') {
            return [];
        }

        return [
            Attachment::fromData(fn () => $ics, $payload['ics_filename'] ?? 'interview.ics')
                ->withMime('text/calendar; charset=utf-8; method=REQUEST'),
        ];
    }

    /** @return array{subject: string, paragraphs: list<string>, action: ?string} */
    private function rendered(): array
    {
        $payload = (array) ($this->message->payload ?? []);

        return RecruitmentTemplates::render(
            (string) $this->message->template_key,
            (array) ($payload['data'] ?? []),
        );
    }
}
