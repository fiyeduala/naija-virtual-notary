<?php

/*
| Naija Virtual Notary — recruitment.
|
| Everything the owner is expected to decide lives here or in Platform
| settings, and nothing in this file invents a term of employment. Salary,
| headcount, commission rates and reporting lines are deliberately absent:
| they belong on the vacancy record, entered by whoever is authorised to
| promise them.
|
| Values an administrator may change at runtime are read through
| App\Support\Settings so the panel can override them; the numbers here are
| the documented fallbacks.
*/

return [

    // The feature switch. Off leaves every public careers route 404 and hides
    // the whole Recruitment group in the panel, so the module can be merged
    // and migrated long before anybody is meant to see it.
    'enabled' => env('NVN_RECRUITMENT_ENABLED', false),

    /*
    | Working time.
    |
    | Stored timestamps are UTC everywhere in this application; this is the
    | business clock used to work out "three working days" and to render a
    | deadline a candidate can act on. Monday-Friday by default because that
    | is the office's week, not because weekends are impossible.
    */
    'timezone'      => env('NVN_RECRUITMENT_TZ', 'Africa/Lagos'),
    'working_days'  => [1, 2, 3, 4, 5], // Carbon: 1 = Monday
    'working_hours' => ['start' => '09:00', 'end' => '17:00'],

    // Y-m-d dates the office is closed. Public holidays move every year and
    // are a political fact rather than a calculable one, so they are listed
    // rather than derived. An empty list is honest, not broken.
    'holidays' => [],

    /*
    | Assessments.
    */
    'assessment' => [
        // Working days from the invitation actually being dispatched — not
        // from the admin approving it, which may be days earlier if a delay
        // was chosen.
        'due_working_days' => (int) env('NVN_ASSESSMENT_DUE_DAYS', 3),

        // The hour, in the business timezone, a deadline falls on.
        'due_at_time' => env('NVN_ASSESSMENT_DUE_TIME', '17:00'),

        // How long the work should take. Shown to the candidate before they
        // start, because an unbounded "take-home" is how good applicants are
        // lost.
        'effort_minutes' => [30, 45],

        // One reminder, roughly a working day before the deadline.
        'reminders' => [
            'enabled'              => true,
            'working_days_before'  => 1,
        ],

        // An extension is an explicit act with a reason, never a silent
        // sliding window.
        'max_extension_days' => 10,
    ],

    /*
    | Invitation timing.
    |
    | An administrator approves, and then chooses: now, or after one, two or
    | three working days. The delay is deliberate and recorded — never
    | randomised, because a candidate comparing notes with another candidate
    | should find the same policy.
    */
    'invitation_delays' => [0, 1, 2, 3],

    /*
    | Uploads.
    |
    | PDF and DOCX only for a CV. The extension list and the MIME list must
    | both pass: an extension is a claim and a MIME sniff is evidence, and a
    | file that disagrees with itself is refused.
    */
    'uploads' => [
        'cv' => [
            'max_kb'     => (int) env('NVN_CV_MAX_KB', 5120),
            'extensions' => ['pdf', 'docx'],
            'mimes'      => [
                'application/pdf',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                // Some clients send a .docx as a bare zip. Accepted only when
                // the extension is also .docx — see App\Support\UploadGuard.
                'application/zip',
            ],
        ],

        'onboarding' => [
            'max_kb'     => (int) env('NVN_ONBOARDING_MAX_KB', 8192),
            'extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
            'mimes'      => ['application/pdf', 'image/jpeg', 'image/png'],
        ],

        // Extensions refused whatever the MIME header claims. Executables and
        // anything the browser might run if it were ever served inline.
        'forbidden_extensions' => [
            'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
            'exe', 'com', 'bat', 'cmd', 'sh', 'bash', 'ps1', 'psm1', 'vbs',
            'js', 'mjs', 'jar', 'msi', 'dll', 'so', 'scr', 'hta', 'html',
            'htm', 'xhtml', 'svg', 'swf',
        ],
    ],

    /*
    | Malware scanning.
    |
    | Off by default because most shared hosts have no scanner. When it is
    | off, a stored file is recorded as `unscanned` and never as clean — the
    | panel says so next to the download. When it is on and the scan FAILS,
    | the file is quarantined and the upload is refused: failing closed is the
    | only safe direction for a document a stranger chose.
    */
    'scanner' => [
        'enabled' => env('NVN_RECRUITMENT_SCANNER', false),
        'command' => env('NVN_RECRUITMENT_SCANNER_CMD', 'clamscan --no-summary'),
        'timeout' => 60,
    ],

    /*
    | The outbox, and how fast it is allowed to send.
    |
    | Every candidate email is queued, never sent inline, and this is the
    | ceiling on how quickly the drain empties that queue. It exists because
    | throughput and deliverability are different problems: the queue already
    | guarantees one message per recipient with no blast, but four thousand
    | invitations pushed out as fast as the server accepts them is still how a
    | domain with little sending history gets itself greylisted or filtered —
    | and most shared hosts enforce an hourly relay cap of their own that is
    | lower than you would guess. Hitting that cap produces bounces from our
    | own server, which is worse than sending slowly.
    |
    | `per_hour` counts only paceable mail. The messages in
    | RecruitmentTemplates::PRIORITY — a sign-in link, an interview, an offer,
    | a receipt — are sent regardless and are not counted against it, because a
    | sign-in link held behind a batch expires before it arrives.
    |
    | 0 means no ceiling. The default is deliberately low enough to be safe on
    | a subdomain that has not sent at volume before. Raise it once the domain
    | has a reputation and once you know the host's own cap, not before.
    |
    | The second window is what makes the sending even rather than a burst at
    | the top of each hour. It is derived, not configured: the hourly figure
    | divided across twelve runs, twelve being an hour of the slowest cron
    | this kind of host allows. The effect is that the pace comes out the same
    | whether `schedule:run` fires every minute or every five.
    |
    | `per_run` is the other half of the same number, and on a host whose cron
    | will not fire more often than every five minutes it is the one that
    | actually binds. A run attempts at most this many messages, so the real
    | ceiling is `per_run` x however often the cron fires — twelve times an
    | hour at that floor. Raising `per_hour` past that does nothing on its own.
    | Keep the two in step: `per_run` wants to be at least `per_hour` / 12.
    */
    'outbox' => [
        'per_hour' => (int) env('NVN_RECRUITMENT_PER_HOUR', 100),
        'per_run'  => (int) env('NVN_RECRUITMENT_PER_RUN', 25),
    ],

    /*
    | Candidate access.
    |
    | Passwordless. A candidate is not a customer and must not be made to
    | invent a password for the platform to hold.
    */
    'candidate_access' => [
        'token_minutes'   => (int) env('NVN_CANDIDATE_TOKEN_MINUTES', 30),
        'session_minutes' => (int) env('NVN_CANDIDATE_SESSION_MINUTES', 120),
    ],

    /*
    | Retention.
    |
    | Deliberately null. A retention period is a decision with legal weight
    | and the owner has to make it; a default of "two years" invented here
    | would be this file quietly setting policy for somebody else's business.
    | Automatic purging stays off until `months` is configured in Platform
    | settings, and the readiness check reports it as missing until then.
    */
    'retention' => [
        'months'       => env('NVN_RECRUITMENT_RETENTION_MONTHS'), // null = unset
        'auto_purge'   => env('NVN_RECRUITMENT_AUTO_PURGE', false),
        'hired_months' => env('NVN_RECRUITMENT_HIRED_RETENTION_MONTHS'),
    ],

    /*
    | The privacy notice shown on the application form.
    |
    | The version string is stored against every acknowledgment, so a notice
    | that is edited later does not retroactively claim consent to wording
    | nobody saw. BUMP THE VERSION whenever the text changes.
    */
    'privacy' => [
        'version' => env('NVN_RECRUITMENT_PRIVACY_VERSION', '2026-10-13.1'),
    ],

    // Where candidate replies should go. Falls back to the platform's own
    // from-address rather than inventing a careers@ mailbox that may not
    // exist — a reply-to nobody reads is worse than none.
    'reply_to' => env('NVN_RECRUITMENT_REPLY_TO'),

    // The four role categories being recruited for. Used by the seeder and by
    // the assessment templates; the vacancy itself stores its own copy.
    'role_keys' => [
        'customer_service',
        'marketing_executive',
        'digital_marketing',
        'hr_manager',
    ],

    /*
    | Intro video.
    |
    | Links only in the first release. A 60-120 second video is 20-80MB and a
    | shared cPanel account has neither the disk nor the request timeout for a
    | queue of them. Candidates who will not post a video get a written or
    | audio alternative, always.
    */
    'intro_video' => [
        'enabled_by_default' => false,
        'min_seconds'        => 60,
        'max_seconds'        => 120,
        'allowed_hosts'      => [
            'youtube.com', 'www.youtube.com', 'youtu.be', 'm.youtube.com',
            'vimeo.com', 'player.vimeo.com',
            'drive.google.com', 'loom.com', 'www.loom.com',
        ],
    ],

    // Guarantors are off unless a role explicitly turns them on with a
    // reason. They are not references and the two are not interchangeable.
    'guarantors' => [
        'enabled_by_default' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pre-employment checks
    |--------------------------------------------------------------------------
    |
    | The checklist built when an accepted offer moves into checks. Identity
    | documents and guarantors are per-vacancy switches and are not here;
    | these are the items every checklist gets.
    |
    | `professional_references` is how many referees are asked for. Set it to
    | 0 and no referee is requested at all. A referee is a person who worked
    | with the candidate; a guarantor is someone who stands financially behind
    | them. They are different things and this module never treats one as the
    | other.
    |
    */

    'checks' => [
        'professional_references' => 2,

        // Asking the candidate to confirm in writing that they accept the
        // terms as issued. This is a record of agreement, not a signature,
        // and nothing in this module describes it as one.
        'confirm_terms' => true,
    ],
];
