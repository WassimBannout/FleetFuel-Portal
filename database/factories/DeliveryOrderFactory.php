<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\DeliveryStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryOrder>
 */
class DeliveryOrderFactory extends Factory
{
    /**
     * A pending order created by a manager of the same company.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'created_by' => fn (array $attributes) => User::factory()->state([
                'role' => UserRole::CompanyManager,
                'company_id' => $attributes['company_id'],
                'station_id' => null,
            ]),
            'address' => fake()->streetAddress().', Test City',
            'governorate' => 'Beirut',
            'liters' => '1500.00',
            'preferred_start_at' => now()->addDays(2)->setTime(8, 0),
            'preferred_end_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['preferred_start_at'])->addHours(4),
            'status' => DeliveryStatus::Pending,
        ];
    }

    /**
     * Every order starts its timeline with a null -> pending history row.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (DeliveryOrder $order): void {
            DeliveryStatusHistory::query()->forceCreate([
                'delivery_order_id' => $order->id,
                'from_status' => null,
                'to_status' => DeliveryStatus::Pending,
                'changed_by' => $order->created_by,
                'changed_at' => $order->created_at,
            ]);
        });
    }
}
