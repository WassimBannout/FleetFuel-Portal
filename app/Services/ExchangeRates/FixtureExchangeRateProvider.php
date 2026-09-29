<?php

namespace App\Services\ExchangeRates;

use App\Contracts\ExchangeRateProvider;
use App\Enums\RateSource;
use App\Support\Decimal;
use App\Support\RateObservation;
use Carbon\CarbonImmutable;

/**
 * Fixture mode: no HTTP request. Each UTC day gets one synthetic observation
 * at midnight with the configured fictional constant, labeled `fixture`, so
 * a local demo keeps working after its seeded rates expire.
 */
final class FixtureExchangeRateProvider implements ExchangeRateProvider
{
    public function latest(): RateObservation
    {
        $today = CarbonImmutable::now()->utc()->startOfDay();

        return new RateObservation(
            RateSource::Fixture,
            (string) Decimal::normalize((string) config('fleetfuel.exchange_rates.fixture_rate'), 8),
            $today,
            null,
            $today->addDay(),
        );
    }
}
