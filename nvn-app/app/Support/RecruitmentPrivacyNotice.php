<?php

namespace App\Support;

/**
 * The recruitment privacy notice, and its version.
 *
 * Two things are deliberate here.
 *
 * First, the text below is a DRAFT for the owner to review. It describes what
 * this module actually does — which is the part software can be sure of — but
 * nothing in this codebase can know whether it satisfies the NDPA or any other
 * law, and this class does not claim it does. The admin screen says so in those
 * words, and the text is editable precisely because a lawyer may need to change
 * it.
 *
 * Second, the VERSION is stored on every application alongside the timestamp of
 * the tick. If the notice is rewritten, applications already submitted still
 * record which version their candidate actually read — a consent record that
 * silently points at today's text is not a consent record.
 */
class RecruitmentPrivacyNotice
{
    public const SETTING_BODY    = 'recruitment_privacy_notice';
    public const SETTING_VERSION = 'recruitment_privacy_version';
    public const SETTING_CONTACT = 'recruitment_privacy_contact';

    /** The version stamped onto a new application. */
    public static function version(): string
    {
        return Settings::string(
            self::SETTING_VERSION,
            (string) config('recruitment.privacy.version', '1'),
        );
    }

    /** Where a candidate writes to about their own data. */
    public static function contact(): ?string
    {
        $contact = trim(Settings::string(self::SETTING_CONTACT, ''));

        if ($contact !== '') {
            return $contact;
        }

        // Falls back to the configured reply-to, and to nothing at all if that
        // is unset too. An invented address on a privacy notice is worse than
        // an absent one: it tells somebody to write to a mailbox that does not
        // exist, about the one subject where they are entitled to an answer.
        $replyTo = config('recruitment.reply_to');

        return is_string($replyTo) && trim($replyTo) !== '' ? trim($replyTo) : null;
    }

    /** True while nobody has configured where requests should go. */
    public static function contactMissing(): bool
    {
        return self::contact() === null;
    }

    /** Has the owner replaced the shipped draft? */
    public static function isDefault(): bool
    {
        return trim(Settings::string(self::SETTING_BODY, '')) === '';
    }

    /** The notice itself: the owner's text, or the draft below. */
    public static function body(): string
    {
        $body = trim(Settings::string(self::SETTING_BODY, ''));

        return $body !== '' ? $body : self::draft();
    }

    /**
     * The notice as sections, for rendering.
     *
     * Plain text throughout, split on blank lines, with a line ending in a
     * colon treated as a heading. There is no HTML anywhere in this path: the
     * notice is administrator-editable, and an editable field that renders as
     * markup is a stored-XSS hole on a public page.
     *
     * @return list<array{heading: ?string, paragraphs: list<string>}>
     */
    public static function sections(): array
    {
        $sections = [];
        $current  = ['heading' => null, 'paragraphs' => []];

        foreach (preg_split('/\R{2,}/', self::body()) ?: [] as $block) {
            $block = trim((string) $block);

            if ($block === '') {
                continue;
            }

            if (! str_contains($block, "\n") && str_ends_with($block, ':')) {
                if ($current['heading'] !== null || $current['paragraphs'] !== []) {
                    $sections[] = $current;
                }

                $current = ['heading' => rtrim($block, ':'), 'paragraphs' => []];

                continue;
            }

            $current['paragraphs'][] = $block;
        }

        if ($current['heading'] !== null || $current['paragraphs'] !== []) {
            $sections[] = $current;
        }

        return $sections;
    }

    /**
     * The shipped draft. For review, not for relying on.
     *
     * Written to match what the code does rather than to sound reassuring: if
     * a sentence here is not true of the implementation, the sentence is the
     * bug. The retention paragraph deliberately says the period is set by the
     * office, because this module refuses to pick one.
     */
    public static function draft(): string
    {
        $contact = self::contact() ?? '[no contact address has been configured yet]';

        return implode("\n\n", [
            'Why we collect this:',

            'We collect what you send us through our careers pages in order to consider you for '
                . 'the role you applied for: to read your application, to set you a short exercise, '
                . 'to arrange an interview, and to make a hiring decision. We do not use it for '
                . 'anything else, and we do not sell it or share it with another employer.',

            'What we collect:',

            'Your name, email address, phone number, the country and city you work from and your '
                . 'time zone. Your CV. What you wrote about why you want the role and the experience '
                . 'you have. Any links you chose to give us. What you told us about your expected pay, '
                . 'your availability, and your working arrangements. Your answers to the questions on '
                . 'the form and to the exercise, if we set you one. Notes our team writes while '
                . 'reviewing, and the record of each step your application moved through.',

            'If we make you an offer, and only then, we may ask for identity documents or for '
                . 'referees. We do not ask for those while we are still deciding, and we never ask '
                . 'for your bank details or for any payment at any stage of hiring.',

            'Who can see it:',

            'Only the members of our team who are reviewing for that role, and only the parts they '
                . 'need. Identity documents and any guarantor paperwork are restricted further, to '
                . 'the people responsible for those checks. Your files are stored privately and are '
                . 'not published or linked anywhere public.',

            'Who else is involved:',

            'Our website and database are hosted by our hosting provider, and our emails are sent '
                . 'through our email provider. They process this information on our behalf in order '
                . 'to run the service. If we interview you by video call, that call takes place on a '
                . 'third-party meeting service, and we will tell you which one when we arrange it.',

            'How long we keep it:',

            'We keep unsuccessful applications for the period set by our office, after which they '
                . 'are deleted, and we delete the files along with the records. If you asked us to '
                . 'keep your details for future roles, we keep them on that basis until you tell us '
                . 'to stop. One limitation we would rather state than hide: our database backups are '
                . 'kept on a rolling cycle by our host, so a deleted record can remain inside an '
                . 'older backup until that backup expires.',

            'Your choices:',

            'You can withdraw your application at any time from your applicant page, and we will '
                . 'stop processing it and cancel anything we had queued to send you. You can ask us '
                . 'what we hold about you, ask us to correct it, or ask us to delete it. Keeping your '
                . 'details for future roles is a separate, optional tick on the form, and leaving it '
                . 'blank has no effect on the application itself.',

            'Automated decisions:',

            'We do not make hiring decisions automatically. Nothing on this platform ranks, scores '
                . 'or rejects candidates by itself; every exercise is read by a person and every '
                . 'decision is made by a person. We do not analyse faces, voices or personality, and '
                . 'we do not infer anything from the demographics of an applicant.',

            'Contacting us:',

            'Write to ' . $contact . ' about anything in this notice, including a request to see, '
                . 'correct or delete what we hold about you.',
        ]);
    }
}
