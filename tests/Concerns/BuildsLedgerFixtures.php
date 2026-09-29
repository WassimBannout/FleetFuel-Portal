<?php

namespace Tests\Concerns;

use App\Enums\ProductCode;
use App\Models\Company;
use App\Models\Driver;
use App\Models\ExchangeRate;
use App\Models\FuelCard;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;

/**
 * Small, valid fixtures for database tests, plus the full demo seed.
 * Tests using this trait should freeze time at FIXTURE_NOW first.
 */
trait BuildsLedgerFixtures
{
    /** Matches as_of_utc in docs/examples/fixtures.json. */
    protected const FIXTURE_NOW = '2026-09-28T09:00:00Z';

    protected const DEMO_PASSWORD = 'test-demo-password';

    /**
     * One diesel card on a 60 L vehicle with 100 L / 100 USD limits, a station
     * with its operator, a DIESEL price of 80000 LBP/L and a fixture rate of
     * 89500 valid now; plus a second company and station for isolation tests.
     *
     * @return array{company: Company, otherCompany: Company, station: Station, otherStation: Station, operator: User, otherOperator: User, admin: User, manager: User, diesel: Product, petrol: Product, vehicle: Vehicle, driver: Driver, card: FuelCard}
     */
    protected function ledgerWorld(): array
    {
        $admin = User::factory()->admin()->create();
        $company = Company::factory()->create();
        $station = Station::factory()->create();
        $otherStation = Station::factory()->create();

        $diesel = Product::factory()->forCode(ProductCode::Diesel)->create();
        $petrol = Product::factory()->forCode(ProductCode::Ulp95)->create();

        foreach ([$diesel, $petrol] as $product) {
            ProductPrice::factory()->create([
                'product_id' => $product->id,
                'price_lbp' => '80000.0000',
                'effective_from' => CarbonImmutable::now()->subDays(10),
                'created_by' => $admin->id,
            ]);
        }

        ExchangeRate::factory()->create(['effective_at' => CarbonImmutable::now()->startOfDay()]);

        $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'tank_capacity_l' => '60.00']);
        $driver = Driver::factory()->create(['company_id' => $company->id]);

        return [
            'company' => $company,
            'otherCompany' => Company::factory()->create(),
            'station' => $station,
            'otherStation' => $otherStation,
            'operator' => User::factory()->stationOperator($station)->create(),
            'otherOperator' => User::factory()->stationOperator($otherStation)->create(),
            'admin' => $admin,
            'manager' => User::factory()->companyManager($company)->create(),
            'diesel' => $diesel,
            'petrol' => $petrol,
            'vehicle' => $vehicle,
            'driver' => $driver,
            'card' => FuelCard::factory()->assignedTo($vehicle, $driver)->restrictedTo($diesel)->create(),
        ];
    }

    protected function seedDemo(string $asOf = self::FIXTURE_NOW): void
    {
        config([
            'fleetfuel.demo.enabled' => true,
            'fleetfuel.demo.password' => self::DEMO_PASSWORD,
        ]);

        $this->artisan('demo:seed', ['--as-of' => $asOf])->assertSuccessful();
    }
}
