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
