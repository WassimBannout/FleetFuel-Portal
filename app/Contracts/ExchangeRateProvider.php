<?php

namespace App\Contracts;

use App\Exceptions\ExchangeRateFetchFailed;
use App\Support\RateObservation;

/**
 * Source of the latest USD/LBP observation. Only `rates:sync` calls it:
 * page requests, POS ingestion and reports read stored rates instead.
 *
 * The container binds the implementation for EXCHANGE_RATE_MODE: the HTTP
 * provider in live mode, the synthetic fixture in fixture mode.
 */
interface ExchangeRateProvider
{
    /**
     * @throws ExchangeRateFetchFailed when no valid observation was obtained
     */
    public function latest(): RateObservation;
}
