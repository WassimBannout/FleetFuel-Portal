<?php

namespace Database\Seeders;

use App\Enums\ProductCode;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Safe reference data for any environment: the three fuel products.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $created = self::ensureReferenceProducts();

        $this->command->info("Reference products: {$created} created, the rest already existed.");
    }

    /**
     * Insert any missing reference product. Existing rows are never modified,
     * so an admin's later edits survive every repeated setup.
     */
    public static function ensureReferenceProducts(): int
    {
        $created = 0;

        foreach (ProductCode::cases() as $code) {
            if (Product::query()->where('code', $code->value)->exists()) {
                continue;
            }

            Product::query()->forceCreate([
                'code' => $code->value,
                'name' => $code->label(),
                'fuel_type' => $code->fuelType(),
                'unit' => 'L',
                'is_active' => true,
            ]);
            $created++;
        }

        return $created;
    }
}
