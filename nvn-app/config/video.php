<?php

return [
    /*
    | Active provider: 'daily' | 'agora' (skeleton) | 'manual' (placeholder).
    | The binding lives in AppServiceProvider::register(). 'manual' returns no
    | token, which puts the call screen into preview mode — the rest of the
    | notarization flow still works, so the site is usable before video is set up.
    */
    'provider' => env('VIDEO_PROVIDER', 'manual'),

    /*
    | Recording is off by design for the verification call. The defensible
    | evidence is the VerificationRecord plus the audit log, not a video file.
    |
    | Note for Daily: its API has no way to say "recording: false" — the
    | enable_recording property only accepts 'cloud', 'cloud-audio-only',
    | 'local' or 'raw-tracks'. Recording is therefore off because we never ask
    | for it, on the room or on the meeting token. The one thing that could
    | switch it on behind our back is a domain-level default set in the Daily
    | dashboard, so DailyVideoProvider reads each room back and logs a warning
    | if Daily reports recording enabled. Leave recording unset on the domain.
    */
    'recording_enabled' => false,

    'daily' => [
        // Dashboard → Developers → API key.
        'api_key' => env('DAILY_API_KEY'),

        // Your Daily subdomain. Either 'yourteam' or the full 'yourteam.daily.co'.
        'domain'  => env('DAILY_DOMAIN'),

        'api_url' => env('DAILY_API_URL', 'https://api.daily.co/v1'),

        // Daily Prebuilt. Served from a CDN; pin a version here if you would
        // rather not track their latest.
        'js_url'  => env('DAILY_JS_URL', 'https://unpkg.com/@daily-co/daily-js'),

        // How long a room stays joinable, counted from the scheduled start (or
        // from now, for on-demand sessions). Nobody can join after this; anyone
        // already on the call stays on it.
        'room_ttl_minutes' => (int) env('DAILY_ROOM_TTL_MINUTES', 240),

        // Client + notary, with headroom for an admin stepping in as fallback.
        'max_participants' => (int) env('DAILY_MAX_PARTICIPANTS', 5),

        // The camera/mic check before joining. Worth the extra click — most
        // "I can't hear you" happens because a device was wrong from the start.
        'prejoin_ui' => (bool) env('DAILY_PREJOIN_UI', true),

        // Seconds. Kept short: this runs inside a page load, and a slow Daily
        // should drop the screen to preview mode quickly rather than hang.
        'timeout' => (int) env('DAILY_TIMEOUT', 6),
    ],

    'agora' => [
        'app_id'          => env('AGORA_APP_ID'),
        'app_certificate' => env('AGORA_APP_CERTIFICATE'),
    ],
];
