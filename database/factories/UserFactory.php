<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state: a manager of a new company, because
     * a scoped role is a safer default in tests than an accidental admin.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::CompanyManager,
            'company_id' => Company::factory(),
            'station_id' => null,
            'is_active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state([
            'role' => UserRole::Admin,
            'company_id' => null,
            'station_id' => null,
        ]);
    }

    public function companyManager(?Company $company = null): static
    {
        return $this->state([
            'role' => UserRole::CompanyManager,
            'company_id' => $company ?? Company::factory(),
            'station_id' => null,
        ]);
    }

    public function stationOperator(?Station $station = null): static
    {
        return $this->state([
            'role' => UserRole::StationOperator,
            'company_id' => null,
            'station_id' => $station ?? Station::factory(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
