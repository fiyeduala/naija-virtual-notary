<?php

return [

    /*
    | Default platform commission (percentage retained by the platform).
    | Per the Build Plan, default is 50% and is overridable per notary.
    */
    'default_commission_rate' => 50,

    /*
    | Fallback rule (Build Plan section 4.6).
    | Minutes a notary has to accept a paid request before it reassigns to admin.
    */
    'fallback_minutes' => 30,

    /*
    | Whether the fallback timer runs around the clock (true) or only within the
    | notary's stated availability hours (false). Default: around the clock.
    */
    'fallback_round_the_clock' => true,

    /*
    | When the admin handles a fallback notarization, does the platform retain the
    | full fee (true) or still credit the notary their share (false)?
    | Default: platform retains the full fee.
    */
    'fallback_platform_keeps_full_fee' => true,

    /*
    | Default verification call slot length, in minutes.
    */
    'session_slot_minutes' => 30,

    /*
    | Standard onboarding fee for notary partners, in NGN kobo (₦30,000 = 3,000,000 kobo).
    | Confirm against the imported WordPress data.
    */
    'onboarding_fee_ngn' => 3000000,

    /*
    | Offsite notarization: what a notary pays to seal ONE document they
    | brought in themselves, in NGN kobo (₦1,000 = 100,000 kobo).
    |
    | Only the fallback. The live figure is set by the admin under Platform
    | settings — see App\Support\Settings::offsiteFeeMinor().
    */
    'offsite_fee_ngn' => 100000,

    /*
    | Automatic notary payouts via Paystack Transfers.
    |
    | Off by default, and deliberately so: transfers debit the Paystack balance
    | and cannot be recalled, so moving real money must be something the platform
    | is switched INTO rather than something it does because nobody switched it
    | off. With this false the payout ledger still works exactly the same — the
    | admin settles each payout by hand and records how.
    |
    | Overridable from Platform settings; this is only the fallback.
    */
    'paystack_transfers' => env('NVN_PAYSTACK_TRANSFERS', false),

    /*
    | How long the scheduled queue worker runs before exiting, in seconds.
    |
    | It should be just under the interval of the server cron that calls
    | `schedule:run`, so a worker is alive for as much of each window as
    | possible. 55 suits the per-minute cron in routes/console.php. Some shared
    | hosts refuse anything more frequent than every five minutes — set
    | NVN_QUEUE_MAX_TIME=290 there, or queued email sits idle for four minutes
    | out of every five.
    */
    'queue_max_time' => (int) env('NVN_QUEUE_MAX_TIME', 55),

    /*
    | Tawk.to live chat.
    |
    | Paste the two IDs from the widget's embed code — Tawk gives you a src of
    | https://embed.tawk.to/<property_id>/<widget_id>. Either set them here via
    | .env, or, more easily, in Platform settings, which overrides this.
    |
    | Leave them blank and nothing is loaded at all: no script tag, no request
    | to Tawk, no cookie. The widget is opt-in.
    |
    | It renders on every visitor-facing page and nowhere else. The admin panel
    | is excluded because staff talk to each other in the panel's own message
    | threads, and 'hidden_routes' takes the routes where a floating widget is
    | actively unwelcome — it would sit on top of a live notarization.
    */
    'tawk' => [
        'property_id'   => env('TAWK_PROPERTY_ID', ''),
        'widget_id'     => env('TAWK_WIDGET_ID', 'default'),
        'hidden_routes' => [
            'session.join',
            'session.notarize',
            'session.done',
        ],
    ],

    /*
    | Supported currencies and countries.
    */
    'currencies' => ['NGN', 'USD'],
    'countries'  => ['Nigeria', 'Ghana', 'United Kingdom', 'United States', 'Canada', 'Other'],

    /*
    | OTP email verification gate.
    | Set to false (NVN_REQUIRE_OTP=false in .env) to bypass the gate during
    | development / UAT. Must be true before going to production.
    */
    'require_otp_verification' => env('NVN_REQUIRE_OTP', true),

    /*
    | System-native notary — the admin account that handles fallbacks.
    | Set during seeding; this is the user email that owns the system-native profile.
    */
    'system_native_email' => env('NVN_SYSTEM_NATIVE_EMAIL', 'admin@naijavirtualnotary.com'),

    /*
    | The old WordPress site — read once, by `nvn:import-wordpress`.
    |
    | 'path' is the WordPress document root on disk, needed because CFDB7 keeps
    | uploaded files on the filesystem and only the filename in the database.
    | Without it the accounts import fine and every attachment is skipped.
    |
    | The three form ids are this install's, discovered from wp_posts where
    | post_type = 'wpcf7_contact_form'. They are configuration rather than
    | constants because a rebuilt form gets a new id, and the whole import then
    | silently matches nothing rather than failing loudly.
    */
    'wordpress' => [
        'prefix' => env('WP_TABLE_PREFIX', 'wp_'),
        'path'   => env('WP_PATH'),

        'forms' => [
            // "Request Notarization" — client intake.
            'request'   => (int) env('WP_FORM_REQUEST', 656),
            // "Application Form" — notary partner application.
            'application' => (int) env('WP_FORM_APPLICATION', 560),
            // "Partner confirmation" — signature, stamp, logo and bank details.
            'confirmation' => (int) env('WP_FORM_CONFIRMATION', 690),
        ],
    ],

    /*
    | VAPID keys for Web Push notifications.
    | Generate once with: php artisan nvn:vapid-keys
    | Then add VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY to .env
    */
    'vapid_public_key'  => env('VAPID_PUBLIC_KEY', ''),
    'vapid_private_key' => env('VAPID_PRIVATE_KEY', ''),

    /*
    | Rewriting a PDF the free FPDI parser cannot open.
    |
    | FPDI's bundled parser understands PDF 1.4 and older. Anything saved by a
    | current version of Word, Google Docs or Acrobat is 1.5+ and stores its
    | cross-reference table as a compressed stream, which that parser refuses —
    | so a perfectly ordinary document arrives and cannot be sealed.
    |
    | Either of these command line tools rewrites such a file as 1.4 without
    | touching what it says. Left blank, they are looked for on PATH; set a full
    | path if the host keeps them somewhere unusual. Neither is required for the
    | application to run — without one, a 1.5+ PDF is refused with an
    | explanation rather than a stack trace.
    */
    'pdf' => [
        'qpdf'        => env('NVN_QPDF_PATH', 'qpdf'),
        'ghostscript' => env('NVN_GHOSTSCRIPT_PATH', 'gs'),
    ],

    /*
    | Meta (Facebook) advertising — Conversions API.
    |
    | Reports a conversion to Meta when money actually clears, so an ad is
    | optimised against paid notarizations rather than against people who
    | reached the checkout page and left. The report is sent server to server
    | from a queued job; the browser pixel only sets the click cookies and
    | reports page views.
    |
    | 'dataset_id' is the number Events Manager calls the dataset (or pixel) ID.
    | 'access_token' belongs to a System User with ads_management and is a
    | credential of the same weight as PAYSTACK_SECRET_KEY — .env only, never
    | Platform settings, because settings are readable from the admin panel.
    |
    | Leave either blank and nothing at all happens: no pixel script, no
    | third-party request, no cookie, no job. Reporting is opt-in.
    |
    | 'test_event_code' comes from the "Test events" tab and makes events show
    | up there instead of counting. Set it while you are proving the plumbing
    | works, then take it out — events sent with a test code do not optimise
    | anything.
    |
    | 'version' is the Graph API version in the endpoint URL. Meta retires
    | versions roughly two years after release, and a retired one answers with
    | an error rather than a redirect, so this is a knob and not a constant:
    | if `php artisan nvn:meta-check` reports an unsupported version, read the
    | current one off the Conversions API tab in Events Manager and set
    | META_API_VERSION.
    |
    | 'max_value_ngn' is a sanity ceiling, in whole naira. payments.amount is
    | kobo and Meta wants naira, so a missed division reports a ₦45,000 sale as
    | ₦4,500,000 and teaches the optimiser to chase a hundredfold fiction.
    | Nothing above this is sent, and the job says loudly why.
    */
    'meta' => [
        'dataset_id'      => env('META_DATASET_ID', ''),
        'access_token'    => env('META_CAPI_TOKEN', ''),
        'test_event_code' => env('META_TEST_EVENT_CODE', ''),
        'version'         => env('META_API_VERSION', 'v23.0'),
        'max_value_ngn'   => (int) env('META_MAX_VALUE_NGN', 2000000),
    ],

    /*
    |---------------------------------------------------------------------------
    | Audit log
    |---------------------------------------------------------------------------
    |
    | An audit row's hash is taken over its timestamp written out as an ISO8601
    | string, and that string carries the UTC offset — which comes from
    | APP_TIMEZONE at the moment it is rendered. So moving APP_TIMEZONE changes
    | the bytes every earlier row was sealed over, without touching a single
    | value in the table. Every row written before the move stops verifying and
    | every row after it is fine.
    |
    | That happened here. The log opened on 6 August 2026 under APP_TIMEZONE=UTC
    | and the server moved to Africa/Lagos shortly after; entries #1 and #2
    | predate the move. Entry #1's stored hash was reproduced exactly over
    | ...T16:35:36+00:00, proving the row is untouched and that the offset is
    | the only thing that differs.
    |
    | The wrong fix is to recompute those two hashes. They are the only evidence
    | the rows are genuine, and overwriting them to silence a warning would
    | replace that evidence with an assertion — exactly what a forger would do,
    | and indistinguishable from it afterwards.
    |
    | So the verifier is told the truth instead: rows up to and including
    | 'legacy_through_id' were sealed under 'legacy_timezone' and are rendered
    | that way when their hash is checked. This narrows the check rather than
    | loosening it. Those rows now verify against one specific spelling and no
    | other, so editing one still breaks its hash, and nothing changes for any
    | row above the boundary.
    |
    | This ships switched off, and has to be turned on per install. The boundary
    | is a fact about one particular database — on a log that opened after the
    | move, or on a rebuilt one, entries #1 and #2 were sealed in Lagos like
    | everything else, and applying a UTC exception to them would break the two
    | rows it is meant to explain. So it belongs in .env beside the other facts
    | about where this copy runs. On the production server:
    |
    |   AUDIT_LEGACY_TIMEZONE=UTC
    |   AUDIT_LEGACY_THROUGH_ID=2
    |
    | then `php artisan config:cache`.
    |
    | Never raise the boundary to cover a row you have not first explained. A
    | row that fails for any other reason will not verify under a different
    | offset anyway, so moving the line can only ever hide something you have
    | not looked at — and that is how a log stops being evidence.
    */
    'audit' => [
        'legacy_timezone'   => env('AUDIT_LEGACY_TIMEZONE'),
        'legacy_through_id' => (int) env('AUDIT_LEGACY_THROUGH_ID', 0),
    ],

];
