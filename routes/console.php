<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// One USD/LBP sync per day in UTC (docs/04-BUSINESS-RULES.md). The
// `scheduler` container runs `schedule:work`. withoutOverlapping() keeps two
// scheduled runs apart; the command also takes its own lock, so a manual
// `php artisan rates:sync` cannot overlap a scheduled one either.
Schedule::command('rates:sync')
    ->dailyAt((string) config('fleetfuel.exchange_rates.sync_daily_at'))
    ->timezone('UTC')
    ->withoutOverlapping();
