<?php

namespace Database\Factories;

use App\Enums\FuelType;
use App\Models\Company;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'plate_no' => strtoupper(fake()->unique()->bothify('T??-####')),
            'fuel_type' => FuelType::Diesel,
            'tank_capacity_l' => '60.00',
            'odometer_km' => fake()->numberBetween(1000, 250000),
            'is_active' => true,
        ];
    }

    public function petrol(): static
    {
        return $this->state(['fuel_type' => FuelType::Petrol]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
