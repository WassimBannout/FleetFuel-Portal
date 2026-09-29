<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPrice>
 */
class ProductPriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'price_lbp' => '80000.0000',
            'effective_from' => now()->subMonth()->startOfDay(),
            'created_by' => User::factory()->admin(),
        ];
    }
}
