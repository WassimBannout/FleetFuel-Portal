<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'tax_no' => fake()->unique()->bothify('LB-TEST-#####'),
            'status' => CompanyStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => CompanyStatus::Inactive]);
    }
}
