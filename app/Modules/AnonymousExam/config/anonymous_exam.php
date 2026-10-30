<?php

return [
    'bale' => [
        'base_url' => env('BALE_BASE_URL', 'https://tapi.bale.ai'),
        // Secret: read from .env only, never stored in the DB or written to logs
        'token' => env('BALE_BOT_TOKEN'),
        'username' => env('BALE_BOT_USERNAME', 'ITBbot'),
        'update_mode' => env('BALE_UPDATE_MODE', 'polling'), // polling | webhook
        // getchatmember | callback | manual  (see docs/BALE-API-VERIFIED.md)
        'membership_mode' => env('BALE_MEMBERSHIP_MODE', 'getchatmember'),
        // url: deep link button https://ble.ir/<bot>?start=exam_<code>; callback: callback_data button; both: both buttons
        'join_button' => env('BALE_JOIN_BUTTON', 'both'),
        'webhook_secret_path' => env('BALE_WEBHOOK_SECRET'),
        'timeout' => (int) env('BALE_HTTP_TIMEOUT', 15),
        'poll_timeout' => (int) env('BALE_POLL_TIMEOUT', 25),
        'retries' => 3,
        'membership_cache_seconds' => 60,
        'send_per_second' => 20,
    ],

    'anon' => [
        // Secret: must be created once and never rotated (rotating breaks duplicate-vote protection)
        'hmac_secret' => env('ANON_HMAC_SECRET'),
        'token_ttl_minutes' => (int) env('ANON_TOKEN_TTL_MINUTES', 20),
        'session_ttl_minutes' => (int) env('ANON_SESSION_TTL_MINUTES', 120),
        'min_responses_to_show' => (int) env('ANON_MIN_RESPONSES', 5),
        // Phase-2 hardening: store responses encrypted in a pool and flush them in random batches
        'pool_mode' => (bool) env('ANON_POOL_MODE', false),
        'pool_flush_min_batch' => (int) env('ANON_POOL_MIN_BATCH', 3),
    ],

    'display_timezone' => env('APP_TIMEZONE', 'Asia/Tehran'),

    // Public base URL used in participant links (must be reachable from phones, e.g. an ngrok/https URL).
    // Falls back to APP_URL.
    'public_url' => env('EXAM_PUBLIC_URL'),

    // Backup scheduler: also run the minute task after web requests (at most once per minute),
    // in case the host's cron job is not running. Set EXAM_LAZY_SCHEDULER=false to disable.
    'lazy_scheduler' => (bool) env('EXAM_LAZY_SCHEDULER', true),

    'registration_code_ttl_minutes' => 10,

    'default_reminders' => [60, 10],

    'admin' => [
        'login_max_attempts' => 5,
        'path' => 'exam-admin',
    ],
];
