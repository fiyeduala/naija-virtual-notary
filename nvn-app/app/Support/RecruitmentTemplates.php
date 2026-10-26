<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * The ten emails a candidate can receive, in one editable place.
 *
 * Each template is a subject line and a body of plain-text paragraphs. Plain
 * text rather than HTML on purpose: these are editable by an administrator, and
 * an editable HTML body is an injection surface in somebody else's inbox. The
 * layout escapes every paragraph and supplies the branding, so an editor can
 * change the words without being able to change what the email can do.
 *
 * Placeholders are substituted by plain string replacement — never by
 * compiling the text as Blade, which is the same rule the admin broadcast
 * composer follows for the same reason.
 *
 * Two things these templates will not do:
 *
 *   - Invent a contact address. The reply-to is whatever the owner configured,
 *     and when nothing is configured the sentence offering a contact is left
 *     out rather than pointing at a mailbox that may not exist.
 *   - Promise a decision date. No template says "we will be in touch within a
 *     week", because nobody has configured a week.
 */
class RecruitmentTemplates
{
    public const ACKNOWLEDGEMENT  = 'acknowledgement';
    public const ASSESSMENT_SENT  = 'assessment_invitation';
    public const ASSESSMENT_NUDGE = 'assessment_reminder';
    public const ASSESSMENT_GOT   = 'assessment_received';
    public const INTERVIEW_SENT   = 'interview_invitation';
    public const INTERVIEW_MOVED  = 'interview_rescheduled';
    public const OFFER_SENT       = 'offer_sent';
    public const CHECKS_SENT      = 'checks_requested';
    public const REJECTED         = 'application_rejected';
    public const WITHDRAWN        = 'withdrawal_confirmed';
    public const SIGN_IN          = 'sign_in_link';


    /**
     * The messages a rate limit is not allowed to delay.
     *
     * The outbox can be paced, because sending four thousand invitations as
     * fast as the mail server will take them is how a young domain gets itself
     * filtered. But pacing is only safe for mail nobody is sitting waiting for,
     * and these are the ones somebody is:
     *
     *   - SIGN_IN expires in thirty minutes and the candidate is on the page
     *     that asked for it. Held behind a batch it arrives dead, and the
     *     portal looks broken to everybody who tries to use it that hour.
     *   - INTERVIEW_SENT and INTERVIEW_MOVED carry a date and a time. A
     *     rescheduled interview that arrives after the old slot is worse than
     *     no email at all.
     *   - OFFER_SENT is an offer. It does not queue behind anything.
     *   - ASSESSMENT_GOT and WITHDRAWN are receipts for something the person
     *     just did, where silence reads as a failure.
     *
     * Everything else is paceable, and the invitation is pacefully so by
     * design: its deadline is counted from the moment it is actually
     * dispatched, not from when an admin pressed the button, so a slower send
     * moves the deadline with it rather than eating into the candidate's time.
     * The reminder is safe for the same reason in reverse — if pacing pushed
     * one past its deadline, dispatchDue() cancels it instead of sending a
     * nudge about work that can no longer be submitted.
     *
     * These are low-volume by nature: they are sent to individuals at the
     * point of a decision, so exempting them costs nothing against the cap.
     *
     * @var list<string>
     */
    public const PRIORITY = [
        self::SIGN_IN,
        self::INTERVIEW_SENT,
        self::INTERVIEW_MOVED,
        self::OFFER_SENT,
        self::ASSESSMENT_GOT,
        self::WITHDRAWN,
    ];

    /** Whether this template jumps the rate limit. A null key does not. */
    public static function isPriority(?string $key): bool
    {
        return $key !== null && in_array($key, self::PRIORITY, true);
    }

    /** @var array<string, string> */
    public const KEYS = [
        self::ACKNOWLEDGEMENT  => 'Application received',
        self::ASSESSMENT_SENT  => 'Assessment invitation',
        self::ASSESSMENT_NUDGE => 'Assessment reminder',
        self::ASSESSMENT_GOT   => 'Assessment received',
        self::INTERVIEW_SENT   => 'Interview invitation',
        self::INTERVIEW_MOVED  => 'Interview rescheduled',
        self::OFFER_SENT       => 'Offer',
        self::CHECKS_SENT      => 'Pre-employment checks',
        self::REJECTED         => 'Application unsuccessful',
        self::WITHDRAWN        => 'Withdrawal confirmed',
        self::SIGN_IN          => 'Sign-in link',
    ];

    /** The substitutions an editor may use. Anything else is left alone. */
    public const PLACEHOLDERS = [
        '{{ first_name }}'     => "The candidate's first name",
        '{{ full_name }}'      => 'Their full name as given',
        '{{ role }}'           => 'The job title applied for',
        '{{ reference }}'      => 'The application reference',
        '{{ company }}'        => 'Naija Virtual Notary',
        '{{ deadline }}'       => 'The deadline, with its time zone',
        '{{ interview_time }}' => 'The interview time, in both time zones',
        '{{ meeting_url }}'    => 'The meeting link an interviewer supplied',
        '{{ effort }}'         => 'Roughly how long the assessment takes',
    ];

    /**
     * The shipped wording. An override in platform_settings replaces it.
     *
     * @return array<string, array{subject: string, body: string, action: ?string}>
     */
    public static function defaults(): array
    {
        return [
            self::SIGN_IN => [
                'subject' => 'Your sign-in link for {{ company }}',
                'action'  => 'Sign in',
                'body'    => <<<'TXT'
                    Hello,

                    Use the button below to sign in and see your job application.

                    The link works once and expires shortly. You will be asked to confirm before you are signed in, so that an email scanner opening this message cannot use it up before you get here.

                    If you did not ask for this, you can ignore it. Nobody can see your application without this link.
                    TXT,
            ],
            self::ACKNOWLEDGEMENT => [
                'subject' => 'We have your application — {{ role }} ({{ reference }})',
                'action'  => 'Open your application',
                'body'    => <<<'TXT'
                    Hello {{ first_name }},

                    Thank you for applying for the {{ role }} role at {{ company }}. Your application reference is {{ reference }} — please quote it if you write to us about it.

                    A member of our team reads every application. If we would like to take yours further, the next step is a short practical exercise, and we will email you about it.

                    You can see the state of your application at any time using the link below. It asks for your email address and sends you a single-use sign-in link; there is no password to remember.
                    TXT,
            ],
            self::ASSESSMENT_SENT => [
                'subject' => 'A short exercise for your {{ role }} application',
                'action'  => 'Start the exercise',
                'body'    => <<<'TXT'
                    Hello {{ first_name }},

                    We have read your application for the {{ role }} role and would like to see a little of how you work.

                    The exercise takes {{ effort }}. Everything in it is made up for the purpose — there is no real client involved and nothing you write will be used as work.

                    Your deadline is {{ deadline }}. You can save a draft and come back to it. If the deadline is difficult, reply to this email and ask; we would rather move it than receive something rushed.

                    Reference: {{ reference }}
                    TXT,
            ],
            self::ASSESSMENT_NUDGE => [
                'subject' => 'Reminder: your {{ role }} exercise is due {{ deadline }}',
                'action'  => 'Finish the exercise',
                'body'    => <<<'TXT'
                    Hello {{ first_name }},

                    This is a reminder that the exercise for your {{ role }} application is due {{ deadline }}.

                    If you have already submitted it, please ignore this — you do not need to do anything else.

                    Reference: {{ reference }}
                    TXT,
            ],
            self::ASSESSMENT_GOT => [
                'subject' => 'Received: your {{ role }} exercise ({{ reference }})',
                'action'  => 'View your submission',
                'body'    => <<<'TXT'
                    Hello {{ first_name }},

                    We have your exercise for the {{ role }} role. Thank you for the time you spent on it.

                    A member of our team will read it. We will email you either way once it has been reviewed.

                    Reference: {{ reference }}
                    TXT,
            ],
            self::INTERVIEW_SENT => [
                'subject' => 'Interview invitation — {{ role }} at {{ company }}',
                'action'  => 'See the details',
                'body'    => <<<'TXT'
                    Hello {{ first_name }},

                    We would like to speak with you about the {{ role }} role.

                    When: {{ interview_time }}
                    Where: {{ meeting_url }}

                    A calendar invitation is attached. Please let us know if the time does not work for you — you can ask for a different one from the link below.

                    Reference: {{ reference }}
                    TXT,
            ],
            self::INTERVIEW_MOVED => [
                'subject' => 'Your {{ role }} interview has moved',
                'action'  => 'See the new time',
                'body'    => <<<'TXT'
                    Hello {{ first_name }},

                    Your interview for the {{ role }} role has been rescheduled.

                    New time: {{ interview_time }}
                    Where: {{ meeting_url }}

                    An updated calendar invitation is attached; it replaces the previous one rather than adding a second entry. Please ignore the earlier time.

                    Reference: {{ reference }}
                    TXT,
            ],
            self::OFFER_SENT => [
                'subject' => 'An offer for the {{ role }} role at {{ company }}',
                'action'  => 'Read the offer',
                'body'    => <<<'TXT'
                    Hello {{ first_name }},

                    We would like to offer you the {{ role }} role at {{ company }}.

                    The terms are on the page linked below. Please read them, and tell us there whether you accept. If anything is unclear or you would like to discuss it, say so rather than accepting.

                    Reference: {{ reference }}
                    TXT,
            ],
            self::CHECKS_SENT => [
                'subject' => 'A few things we need before you start',
                'action'  => 'Open your checklist',
                'body'    => <<<'TXT'
                    Hello {{ first_name }},

                    Thank you for accepting the {{ role }} role. There are a few items we need before your first day; they are listed on your checklist at the link below.

                    Anything you upload there is stored privately and is seen only by the people in our team who have to see it. Please do not send documents as email attachments.

                    Reference: {{ reference }}
                    TXT,
            ],
            self::REJECTED => [
                'subject' => 'Your application for {{ role }} at {{ company }}',
                'action'  => null,
                'body'    => <<<'TXT'
                    Hello {{ first_name }},

                    Thank you for applying for the {{ role }} role at {{ company }}, and for the time you gave it.

                    On this occasion we are not taking your application further. This is not a judgement of your abilities — we had more strong applicants than places.

                    We wish you well, and we hope you will consider us again.

                    Reference: {{ reference }}
                    TXT,
            ],
            self::WITHDRAWN => [
                'subject' => 'Your application has been withdrawn ({{ reference }})',
                'action'  => null,
                'body'    => <<<'TXT'
                    Hello {{ first_name }},

                    We have withdrawn your application for the {{ role }} role at your request. You will not hear from us about it again.

                    If this was a mistake, or you would like to apply for something else later, you are very welcome to.

                    Reference: {{ reference }}
                    TXT,
            ],
        ];
    }

    /** Where an edited subject or body lives in platform_settings. */
    public static function settingKey(string $key, string $part): string
    {
        return 'recruitment_tpl_' . $key . '_' . $part;
    }

    public static function subject(string $key): string
    {
        $default = static::defaults()[$key]['subject'] ?? '';

        return Settings::string(static::settingKey($key, 'subject'), $default) ?: $default;
    }

    public static function body(string $key): string
    {
        $default = static::defaults()[$key]['body'] ?? '';

        return Settings::string(static::settingKey($key, 'body'), $default) ?: $default;
    }

    /** The label on the one call to action, or null where there is none. */
    public static function action(string $key): ?string
    {
        return static::defaults()[$key]['action'] ?? null;
    }

    /** Save an edited template. Passing the shipped text back clears the override. */
    public static function store(string $key, string $subject, string $body): void
    {
        $defaults = static::defaults()[$key] ?? null;

        if (! $defaults) {
            return;
        }

        PlatformSetting::set(
            static::settingKey($key, 'subject'),
            trim($subject) === trim($defaults['subject']) ? '' : trim($subject),
        );

        PlatformSetting::set(
            static::settingKey($key, 'body'),
            trim($body) === trim($defaults['body']) ? '' : trim($body),
        );

        Settings::flush();
    }

    public static function isEdited(string $key): bool
    {
        $defaults = static::defaults()[$key] ?? null;

        return $defaults
            && (trim(static::subject($key)) !== trim($defaults['subject'])
                || trim(static::body($key)) !== trim($defaults['body']));
    }

    /**
     * Fill a template. Returns the subject and the body split into paragraphs,
     * which is what the layout renders — each one escaped.
     *
     * @param  array<string, string>  $data
     * @return array{subject: string, paragraphs: list<string>, action: ?string}
     */
    public static function render(string $key, array $data): array
    {
        $rendered = static::renderText(
            static::subject($key),
            static::body($key),
            $data,
            static::action($key),
        );

        // An empty subject line would reach an inbox as "(no subject)". The
        // template's own name is a poor subject but it is a true one.
        if ($rendered['subject'] === '') {
            $rendered['subject'] = static::KEYS[$key] ?? 'Naija Virtual Notary';
        }

        return $rendered;
    }

    /**
     * The substitution itself, on text that need not be the stored text.
     *
     * Split out so the settings screen can preview wording an administrator
     * has typed but not yet saved, through the same code that will send it.
     * A preview rendered by a second, nearly-identical implementation is a
     * preview of nothing.
     *
     * @param  array<string, string>  $data
     * @return array{subject: string, paragraphs: list<string>, action: ?string}
     */
    public static function renderText(string $subject, string $body, array $data, ?string $action = null): array
    {
        $find    = [];
        $replace = [];

        foreach ($data as $name => $value) {
            $find[]    = '{{ ' . $name . ' }}';
            $replace[] = (string) $value;
        }

        $subject = trim(str_replace($find, $replace, $subject));
        $body    = str_replace($find, $replace, $body);

        // A placeholder with nothing behind it would otherwise reach an inbox
        // as literal braces. Drop any block that is now only a label.
        $paragraphs = [];

        foreach (preg_split('/\n\s*\n/', $body) ?: [] as $block) {
            $block = trim(preg_replace('/[ \t]*\n[ \t]*/', "\n", (string) $block) ?? '');

            if ($block === '' || str_contains($block, '{{')) {
                continue;
            }

            $paragraphs[] = $block;
        }

        return [
            'subject'    => $subject,
            'paragraphs' => $paragraphs,
            'action'     => $action,
        ];
    }
    /** The configured reply-to, or null. Never a fabricated address. */
    public static function replyTo(): ?string
    {
        $address = Settings::string('recruitment_reply_to', (string) config('recruitment.reply_to', ''));

        return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : null;
    }
}
