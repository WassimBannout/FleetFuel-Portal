<?php

namespace App\Support;

use App\Enums\RateSource;
use Carbon\CarbonImmutable;

/**
 * One validated USD/LBP value as a provider reported it, before it is stored.
 * The rate is already an eight-decimal string; effectiveAt is the provider's
 * own observation time, never the time we happened to fetch it.
 */
final readonly class RateObservation
{
    public function __construct(
        public RateSource $source,
        public string $rate,
        public CarbonImmutable $effectiveAt,
        public ?CarbonImmutable $fetchedAt,
        // When the provider says it will publish its next value, if known.
        public ?CarbonImmutable $nextUpdateAt,
    ) {}
}
