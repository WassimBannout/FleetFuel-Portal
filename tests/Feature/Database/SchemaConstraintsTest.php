<?php

namespace Tests\Feature\Database;

use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\Support\LedgerFixtureBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\TestCase;

/**
 * The database itself refuses invalid data, independently of validation.
 * Writes go through the raw query builder on purpose, bypassing Eloquent.
 */
class SchemaConstraintsTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;

    private const CHECK_VIOLATION = 3819;

    private const DUPLICATE_KEY = 1062;

    private const MISSING_PARENT = 1452;

    private const ROW_IS_REFERENCED = 1451;

    /** @var array{company: Company, otherCompany: Company, station: Station, otherStation: Station, operator: User, otherOperator: User, admin: User, manager: User, diesel: Product, petrol: Product, vehicle: Vehicle, driver: Driver, card: FuelCard} */
    private array $world;

    private FuelTransaction $purchase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->world = $this->ledgerWorld();
        $this->purchase = app(LedgerFixtureBuilder::class)->recordPurchase(
            $this->world['card'], $this->world['station'], $this->world['operator'], $this->world['diesel'],
            '20.00', CarbonImmutable::now()->subHour(), 'POS-TEST-0001', 45000,
        );
    }

    #[DataProvider('invalidRoleScopes')]
    public function test_a_user_has_exactly_the_scope_of_their_role(string $role, bool $withCompany, bool $withStation): void
    {
        $this->assertRejected(self::CHECK_VIOLATION, fn () => DB::table('users')->insert([
            'name' => 'Scope test',
            'email' => 'scope@example.test',
            'password' => 'not-a-real-hash',
            'role' => $role,
            'company_id' => $withCompany ? $this->world['company']->id : null,
            'station_id' => $withStation ? $this->world['station']->id : null,
        ]), 'users_role');
    }

    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function invalidRoleScopes(): array
    {
        return [
            'admin with a company' => ['admin', true, false],
            'admin with a station' => ['admin', false, true],
            'manager without a company' => ['company_manager', false, false],
            'manager with a station as well' => ['company_manager', true, true],
            'operator without a station' => ['station_operator', false, false],
            'operator with a company as well' => ['station_operator', true, true],
            'unknown role' => ['owner', false, false],
        ];
    }

    public function test_a_card_cannot_point_at_another_companys_vehicle_or_driver(): void
    {
        $foreignVehicle = Vehicle::factory()->create(['company_id' => $this->world['otherCompany']->id]);
        $foreignDriver = Driver::factory()->create(['company_id' => $this->world['otherCompany']->id]);
        $card = ['company_id' => $this->world['company']->id, 'card_no' => 'FF-TEST-CROSS', 'status' => 'active'];

        $this->assertRejected(self::MISSING_PARENT, fn () => DB::table('fuel_cards')->insert($card + ['vehicle_id' => $foreignVehicle->id]));
        $this->assertRejected(self::MISSING_PARENT, fn () => DB::table('fuel_cards')->insert($card + ['driver_id' => $foreignDriver->id]));

        // The same vehicle is accepted on a card of its own company.
        DB::table('fuel_cards')->insert([
            'company_id' => $this->world['otherCompany']->id,
            'card_no' => 'FF-TEST-OWN',
            'status' => 'active',
            'vehicle_id' => $foreignVehicle->id,
        ]);
        $this->assertDatabaseHas('fuel_cards', ['card_no' => 'FF-TEST-OWN']);
    }

    public function test_a_ledger_row_must_snapshot_its_cards_own_company(): void
    {
        $this->assertRejected(self::MISSING_PARENT, fn () => DB::table('fuel_transactions')->insert($this->ledgerRow([
            'external_ref' => 'POS-TEST-0002',
            'company_id' => $this->world['otherCompany']->id,
        ])));
    }

    public function test_a_station_cannot_reuse_an_external_ref_but_another_station_can(): void
    {
        $this->assertRejected(self::DUPLICATE_KEY, fn () => DB::table('fuel_transactions')->insert($this->ledgerRow([])));

        DB::table('fuel_transactions')->insert($this->ledgerRow(['station_id' => $this->world['otherStation']->id]));
        $this->assertSame(2, DB::table('fuel_transactions')->where('external_ref', 'POS-TEST-0001')->count());
    }

    public function test_master_data_referenced_by_history_cannot_be_deleted(): void
    {
        $this->assertRejected(self::ROW_IS_REFERENCED, fn () => DB::table('companies')->where('id', $this->world['company']->id)->delete());
        $this->assertRejected(self::ROW_IS_REFERENCED, fn () => DB::table('stations')->where('id', $this->world['station']->id)->delete());
        $this->assertRejected(self::ROW_IS_REFERENCED, fn () => DB::table('fuel_cards')->where('id', $this->world['card']->id)->delete());
        $this->assertRejected(self::ROW_IS_REFERENCED, fn () => DB::table('products')->where('id', $this->world['diesel']->id)->delete());
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('impossibleValues')]
    public function test_the_database_rejects_impossible_values(string $table, array $changes, string $constraint): void
    {
        DeliveryOrder::factory()->create(['company_id' => $this->world['company']->id]);
        $id = DB::table($table)->min('id');

        $this->assertRejected(self::CHECK_VIOLATION, fn () => DB::table($table)->where('id', $id)->update($changes), $constraint);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function impossibleValues(): array
    {
        $schedule = ['scheduled_start_at' => '2026-10-01 08:00:00', 'scheduled_end_at' => '2026-10-01 12:00:00'];

        return [
            'unknown company status' => ['companies', ['status' => 'deleted'], 'companies_status_check'],
            'latitude out of range' => ['stations', ['latitude' => '91.0000000'], 'stations_latitude_check'],
            'tank of zero liters' => ['vehicles', ['tank_capacity_l' => '0.00'], 'vehicles_tank_capacity_positive_check'],
            'negative odometer' => ['vehicles', ['odometer_km' => -1], 'vehicles_odometer_nonnegative_check'],
            'unknown fuel type' => ['vehicles', ['fuel_type' => 'electric'], 'vehicles_fuel_type_check'],
            'negative liter quota' => ['fuel_cards', ['monthly_limit_l' => '-0.01'], 'fuel_cards_limit_l_nonnegative_check'],
            'negative USD quota' => ['fuel_cards', ['monthly_limit_usd' => '-0.01'], 'fuel_cards_limit_usd_nonnegative_check'],
            'unknown card status' => ['fuel_cards', ['status' => 'lost'], 'fuel_cards_status_check'],
            'price of zero' => ['product_prices', ['price_lbp' => '0.0000'], 'product_prices_price_positive_check'],
            'rate of zero' => ['exchange_rates', ['rate' => '0.00000000'], 'exchange_rates_rate_positive_check'],
            'rate expiring when it starts' => ['exchange_rates', ['expires_at' => '2026-09-28 00:00:00'], 'exchange_rates_expiry_check'],
            'manual rate without a reason' => ['exchange_rates', ['source' => 'manual'], 'exchange_rates_manual_reason_check'],
            'ledger row with zero liters' => ['fuel_transactions', ['liters' => '0.00'], 'fuel_transactions_liters_positive_check'],
            'ledger row with a negative USD amount' => ['fuel_transactions', ['amount_usd' => '-0.01'], 'fuel_transactions_amount_usd_check'],
            'ledger row with an unknown rate source' => ['fuel_transactions', ['rate_source' => 'guess'], 'fuel_transactions_rate_source_check'],
            'negative monthly usage' => ['card_monthly_usage', ['used_l' => '-0.01'], 'card_monthly_usage_used_l_check'],
            'delivery window ending before it starts' => ['delivery_orders', ['preferred_end_at' => '2026-01-01 00:00:00'], 'delivery_orders_preferred_window_check'],
            'scheduled without a truck' => ['delivery_orders', ['status' => 'scheduled'] + $schedule, 'delivery_orders_schedule_details_check'],
            'delivered without a delivery time' => ['delivery_orders', ['status' => 'delivered', 'assigned_truck' => 'TRK-9'] + $schedule, 'delivery_orders_delivered_at_check'],
            'cancelled without a reason' => ['delivery_orders', ['status' => 'cancelled'], 'delivery_orders_cancel_reason_check'],
        ];
    }

    public function test_business_identifiers_are_unique_where_the_spec_requires(): void
    {
        $this->assertRejected(self::DUPLICATE_KEY, fn () => FuelCard::factory()->create(['card_no' => $this->world['card']->card_no]));
        $this->assertRejected(self::DUPLICATE_KEY, fn () => Vehicle::factory()->create(['plate_no' => $this->world['vehicle']->plate_no]));
        $this->assertRejected(self::DUPLICATE_KEY, fn () => Driver::factory()->create([
            'company_id' => $this->world['company']->id,
            'license_no' => $this->world['driver']->license_no,
        ]));

        // The same license may exist at another company; tax numbers are optional.
        Driver::factory()->create(['company_id' => $this->world['otherCompany']->id, 'license_no' => $this->world['driver']->license_no]);
        Company::factory()->count(2)->create(['tax_no' => null]);
        $this->assertSame(2, Company::query()->whereNull('tax_no')->count());
    }

    /**
     * A copy of the existing ledger row (without its id), with changes applied.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function ledgerRow(array $changes): array
    {
        $row = (array) DB::table('fuel_transactions')->where('id', $this->purchase->id)->first();
        unset($row['id']);

        return array_merge($row, $changes);
    }

    private function assertRejected(int $mysqlError, Closure $write, ?string $constraint = null): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            $this->assertSame($mysqlError, $e->errorInfo[1] ?? null, $e->getMessage());

            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $e->getMessage());
            }

            return;
        }

        $this->fail('The database accepted a write it should have rejected.');
    }
}
