<?php

namespace Database\Factories;

use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Station>
 */
class StationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' Test Station',
            'district' => fake()->city(),
            'governorate' => fake()->randomElement(['Beirut', 'Mount Lebanon', 'North', 'South', 'Bekaa', 'Nabatieh']),
            'latitude' => null,
            'longitude' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
