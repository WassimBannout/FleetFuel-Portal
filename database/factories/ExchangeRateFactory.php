<?php

namespace Database\Factories;

use App\Enums\RateSource;
use App\Models\ExchangeRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    /**
     * A fixture observation valid for 72 hours from the start of today (UTC).
     * 89500 is a fictional test constant, not a market-rate statement.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'base' => 'USD',
            'quote' => 'LBP',
            'rate' => '89500.00000000',
            'source' => RateSource::Fixture,
            'effective_at' => now()->startOfDay(),
            'fetched_at' => null,
            'expires_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['effective_at'])->addHours(72),
            'created_by' => null,
            'reason' => null,
        ];
    }

    public function provider(): static
    {
        return $this->state([
            'source' => RateSource::Provider,
            'fetched_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['effective_at'])->addMinutes(5),
        ]);
    }

    public function manual(): static
    {
        return $this->state([
            'source' => RateSource::Manual,
            'created_by' => User::factory()->admin(),
            'reason' => 'Test override',
        ]);
    }
}
