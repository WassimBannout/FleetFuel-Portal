<?php

// Project-specific settings. Later milestones read these values through
// config('fleetfuel.*') instead of calling env() in application code.
// The rules behind them are in docs/04-BUSINESS-RULES.md and DECISIONS.md.

return [

    // Timestamps are stored in UTC; quota months and display use this zone.
    'business_timezone' => env('BUSINESS_TIMEZONE', 'Asia/Beirut'),

    'exchange_rates' => [
        // "fixture" uses stored demo observations; "live" calls the provider.
        'mode' => env('EXCHANGE_RATE_MODE', 'fixture'),

        // Fixed server-side endpoint, never user input (docs/SOURCES.md).
        'endpoint' => 'https://open.er-api.com/v6/latest/USD',

        // D09: a rate older than this cannot price a transaction.
        'max_age_hours' => 72,

        // Synthetic LBP per USD used by fixture mode and the demo seed. A
        // fictional test constant, not a statement about the market rate.
        'fixture_rate' => '89500.00000000',

        // Live provider requests (docs/04-BUSINESS-RULES.md, "FX
        // synchronization"): short timeouts, at most three attempts in total
        // with a bounded pause between them, and the provider's documented
        // 20-minute wait after a 429 when it sends no Retry-After header.
        'http' => [
            'connect_timeout_seconds' => 3,
            'timeout_seconds' => 10,
            'attempts' => 3,
            'backoff_milliseconds' => [500, 1000],
            'rate_limited_wait_minutes' => 20,
        ],

        // Provider observation times may run this far ahead of our clock.
        'clock_skew_seconds' => 300,

        // Daily `rates:sync` run, in UTC.
        'sync_daily_at' => '01:00',
    ],

    'pos' => [
        // D08: oldest POS event time accepted, relative to receipt.
        'max_event_age_hours' => 72,
    ],

    'demo' => [
        'enabled' => env('DEMO_MODE', false),
        'password' => env('DEMO_PASSWORD'),
    ],

];
