<?php

namespace Database\Factories;

use App\Enums\FuelType;
use App\Enums\ProductCode;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Test products get throwaway codes; use forCode() or ProductSeeder for
     * the real ULP95/ULP98/DIESEL products.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('TEST-####'),
            'name' => 'Test fuel '.fake()->unique()->numerify('###'),
            'fuel_type' => FuelType::Diesel,
            'unit' => 'L',
            'is_active' => true,
        ];
    }

    public function forCode(ProductCode $code): static
    {
        return $this->state([
            'code' => $code->value,
            'name' => $code->label(),
            'fuel_type' => $code->fuelType(),
        ]);
    }
}
