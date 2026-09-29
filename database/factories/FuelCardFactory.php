<?php

namespace Database\Factories;

use App\Enums\CardStatus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\FuelCard;
use App\Models\Product;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FuelCard>
 */
class FuelCardFactory extends Factory
{
    /**
     * An unassigned, unrestricted card with 100 L / 100 USD monthly limits.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'vehicle_id' => null,
            'driver_id' => null,
            'card_no' => fake()->unique()->numerify('FF-TEST-######'),
            'allowed_product_id' => null,
            'monthly_limit_l' => '100.00',
            'monthly_limit_usd' => '100.00',
            'status' => CardStatus::Active,
        ];
    }

    /**
     * Assign the card within the vehicle's company, as the schema requires.
     */
    public function assignedTo(Vehicle $vehicle, ?Driver $driver = null): static
    {
        return $this->state([
            'company_id' => $vehicle->company_id,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver?->id,
        ]);
    }

    public function restrictedTo(Product $product): static
    {
        return $this->state(['allowed_product_id' => $product->id]);
    }

    public function limits(?string $liters, ?string $usd): static
    {
        return $this->state(['monthly_limit_l' => $liters, 'monthly_limit_usd' => $usd]);
    }

    public function blocked(): static
    {
        return $this->state(['status' => CardStatus::Blocked]);
    }
}
