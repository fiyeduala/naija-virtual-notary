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
    | VAPID keys for Web Push notifications.
    | Generate once with: php artisan nvn:vapid-keys
    | Then add VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY to .env
    */
    'vapid_public_key'  => env('VAPID_PUBLIC_KEY', ''),
    'vapid_private_key' => env('VAPID_PRIVATE_KEY', ''),

];
