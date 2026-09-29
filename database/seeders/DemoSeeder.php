<?php

namespace Database\Seeders;

use App\Enums\CardStatus;
use App\Enums\CompanyStatus;
use App\Enums\DeliveryStatus;
use App\Enums\FuelType;
use App\Enums\RateSource;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CardMonthlyUsage;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\DeliveryStatusHistory;
use App\Models\Driver;
use App\Models\ExchangeRate;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\BusinessMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\LedgerFixtureBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deterministic, fictional demo data relative to an "as of" instant.
 *
 * Guarded: runs only in the local or testing environment with DEMO_MODE on,
 * a DEMO_PASSWORD set and no existing users. It only inserts, so a repeated
 * run changes nothing. `make setup` seeds as of now; use
 * `php artisan demo:seed --as-of=2026-09-28T09:00:00Z` to pick the clock.
 *
 * The simulator cards (FF-ATLAS-001, FF-ATLAS-BLOCKED, FF-ATLAS-TINY,
 * FF-CEDAR-001) start with no usage so POS scenarios behave predictably;
 * dashboard history lives on the other cards (docs/06-UI-SPEC.md).
 */
class DemoSeeder extends Seeder
{
    /** Fictional USD/LBP constant used by fixture observations. */
    public const FIXTURE_RATE = '89500.00000000';

    /** LBP per liter from the start of the as-of Beirut month (docs/examples/fixtures.json). */
    public const CURRENT_PRICES = ['ULP95' => '85000.0000', 'ULP98' => '88000.0000', 'DIESEL' => '80000.0000'];

    /** Earlier prices, so last month's purchases visibly keep their own snapshot. */
    public const PREVIOUS_PRICES = ['ULP95' => '83000.0000', 'ULP98' => '86500.0000', 'DIESEL' => '78000.0000'];

    /** [plate, company, fuel type, tank liters, odometer km] */
    private const VEHICLES = [
        ['ATL-101', 'atlas', 'diesel', '60.00', 44800],
        ['ATL-102', 'atlas', 'diesel', '60.00', 123500],
        ['ATL-103', 'atlas', 'diesel', '200.00', 92000],
        ['ATL-104', 'atlas', 'petrol', '70.00', 56150],
        ['ATL-105', 'atlas', 'diesel', '80.00', 30500],
        ['CED-201', 'cedar', 'diesel', '90.00', 67000],
        ['CED-202', 'cedar', 'petrol', '55.00', 32800],
        ['CED-203', 'cedar', 'diesel', '120.00', 152400],
    ];

    /** [key, company, name, license, phone] */
    private const DRIVERS = [
        ['rami', 'atlas', 'Rami Aoun', 'ATL-DL-1001', '+961 00 100 001'],
        ['lina', 'atlas', 'Lina Chahine', 'ATL-DL-1002', '+961 00 100 002'],
        ['georges', 'atlas', 'Georges Mansour', 'ATL-DL-1003', '+961 00 100 003'],
        ['hadi', 'atlas', 'Hadi Saleh', 'ATL-DL-1004', '+961 00 100 004'],
        ['joanna', 'atlas', 'Joanna Rizk', 'ATL-DL-1005', '+961 00 100 005'],
        ['karim', 'cedar', 'Karim Daou', 'CED-DL-2001', '+961 00 200 001'],
        ['maya', 'cedar', 'Maya Salameh', 'CED-DL-2002', '+961 00 200 002'],
        ['tarek', 'cedar', 'Tarek Hamdan', 'CED-DL-2003', '+961 00 200 003'],
    ];

    /** [card, company, vehicle plate, driver, allowed product, liter limit, USD limit, status] */
    private const CARDS = [
        // Simulator cards: no usage, predictable POS outcomes.
        ['FF-ATLAS-001', 'atlas', 'ATL-101', 'rami', 'DIESEL', '100.00', '100.00', 'active'],
        ['FF-ATLAS-BLOCKED', 'atlas', null, 'lina', 'DIESEL', '100.00', '100.00', 'blocked'],
        ['FF-ATLAS-TINY', 'atlas', 'ATL-105', 'georges', 'DIESEL', '5.00', '100.00', 'active'],
        ['FF-CEDAR-001', 'cedar', 'CED-201', 'karim', 'DIESEL', '100.00', '100.00', 'active'],
        // Dashboard history cards.
        ['FF-ATLAS-H01', 'atlas', 'ATL-102', 'hadi', 'DIESEL', '400.00', '400.00', 'active'],
        ['FF-ATLAS-H02', 'atlas', 'ATL-103', null, 'DIESEL', '300.00', '300.00', 'active'],
        // Petrol vehicle, no product restriction: diesel is still refused.
        ['FF-ATLAS-PETROL', 'atlas', 'ATL-104', 'joanna', null, '250.00', '250.00', 'active'],
        ['FF-CEDAR-H01', 'cedar', 'CED-202', 'maya', 'ULP98', '200.00', '200.00', 'active'],
        ['FF-CEDAR-H02', 'cedar', 'CED-203', null, 'DIESEL', '500.00', '500.00', 'active'],
        // No vehicle, no product restriction, unlimited quotas.
        ['FF-CEDAR-FLEX', 'cedar', null, 'tarek', null, null, null, 'active'],
    ];

    /**
     * [card, station, product, liters, position in the month in per mille,
     *  odometer km, extra minutes]. Positions are fractions of the elapsed
     * month, so any as-of date yields a valid, ordered history.
     */
    private const PREVIOUS_MONTH_PURCHASES = [
        ['FF-ATLAS-H01', 'harbor', 'DIESEL', '45.00', 80, 120000],
        ['FF-ATLAS-H01', 'north', 'DIESEL', '50.00', 300, 120650],
        ['FF-ATLAS-H01', 'harbor', 'DIESEL', '40.00', 550, 121240],
        ['FF-ATLAS-H01', 'harbor', 'DIESEL', '55.00', 850, 121800],
        ['FF-ATLAS-H02', 'north', 'DIESEL', '120.00', 120, 88000],
        ['FF-ATLAS-H02', 'north', 'DIESEL', '100.00', 480, 88900],
        ['FF-ATLAS-H02', 'harbor', 'DIESEL', '60.00', 800, 89650],
        ['FF-ATLAS-PETROL', 'harbor', 'ULP95', '45.00', 100, 54000],
        ['FF-ATLAS-PETROL', 'harbor', 'ULP95', '50.00', 400, 54520],
        ['FF-ATLAS-PETROL', 'north', 'ULP95', '40.00', 750, 55080],
        ['FF-CEDAR-H01', 'north', 'ULP98', '35.00', 150, 31000],
        ['FF-CEDAR-H01', 'north', 'ULP98', '40.00', 500, 31420],
        ['FF-CEDAR-H01', 'harbor', 'ULP98', '30.00', 900, 31900],
        ['FF-CEDAR-H02', 'harbor', 'DIESEL', '100.00', 200, 150000],
        ['FF-CEDAR-H02', 'north', 'DIESEL', '90.00', 600, 150800],
        ['FF-CEDAR-FLEX', 'harbor', 'DIESEL', '20.00', 250, null],
        ['FF-CEDAR-FLEX', 'north', 'ULP95', '30.00', 700, null],
    ];

    private const CURRENT_MONTH_PURCHASES = [
        ['FF-ATLAS-H01', 'harbor', 'DIESEL', '40.00', 100, 122400],
        // Rapid-fill scenario: same vehicle twice, 20 minutes apart.
        ['FF-ATLAS-H01', 'north', 'DIESEL', '30.00', 600, 122950],
        ['FF-ATLAS-H01', 'north', 'DIESEL', '25.00', 600, 122952, 20],
        // Tank-overfill scenario: 75 L into a 60 L tank, still within quota.
        ['FF-ATLAS-H01', 'harbor', 'DIESEL', '75.00', 800, 123500],
        // FF-ATLAS-H02 reaches 250 L; its limit is later cut to 200 L.
        ['FF-ATLAS-H02', 'north', 'DIESEL', '120.00', 200, 90400],
        // Falls inside the manual FX override window (see seedRates()).
        ['FF-ATLAS-H02', 'harbor', 'DIESEL', '80.00', 460, 91300],
        ['FF-ATLAS-H02', 'north', 'DIESEL', '50.00', 700, 92000],
        ['FF-ATLAS-PETROL', 'harbor', 'ULP95', '45.00', 150, 55600],
        ['FF-ATLAS-PETROL', 'harbor', 'ULP95', '50.00', 650, 56150],
        ['FF-CEDAR-H01', 'north', 'ULP98', '40.00', 250, 32350],
        ['FF-CEDAR-H01', 'north', 'ULP98', '35.00', 750, 32800],
        ['FF-CEDAR-H02', 'harbor', 'DIESEL', '100.00', 300, 151600],
        ['FF-CEDAR-H02', 'north', 'DIESEL', '90.00', 850, 152400],
        ['FF-CEDAR-FLEX', 'harbor', 'DIESEL', '25.00', 400, null],
        ['FF-CEDAR-FLEX', 'north', 'ULP95', '35.00', 900, null],
    ];

    /** Below this much elapsed current month the scenarios would not fit. */
    private const MIN_CURRENT_MONTH_SECONDS = 2 * 3600;

    public function __construct(private readonly LedgerFixtureBuilder $builder) {}

    public function run(?string $asOf = null): void
    {
        $this->assertAllowed();

        if (User::query()->exists()) {
            $this->command->info('The database already has users; demo data was not seeded and nothing changed.');

            return;
        }

        $clock = ($asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf))->utc()->setMicrosecond(0);

        DB::transaction(fn () => $this->seed($clock));

        $this->command->info("Demo data seeded as of {$clock->toIso8601ZuluString()}.");
        $this->command->table(['Table', 'Rows'], $this->counts());
    }

    private function assertAllowed(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data may only be seeded in the local or testing environment.');
        }

        if (! config('fleetfuel.demo.enabled')) {
            throw new RuntimeException('DEMO_MODE is off; demo data was not seeded.');
        }

        if (blank(config('fleetfuel.demo.password'))) {
            throw new RuntimeException('Set DEMO_PASSWORD in .env before seeding the demo accounts.');
        }
    }

    private function seed(CarbonImmutable $asOf): void
    {
        $currentMonth = BusinessMonth::for($asOf);
        $currentStart = BusinessMonth::startUtc($currentMonth);
        $previousStart = BusinessMonth::startUtc(CarbonImmutable::parse($currentMonth)->subMonthNoOverflow()->format('Y-m-d'));
        $hasCurrentMonth = $asOf->getTimestamp() - $currentStart->getTimestamp() >= self::MIN_CURRENT_MONTH_SECONDS;

        ProductSeeder::ensureReferenceProducts();
        $products = Product::query()->get()->keyBy('code');

        $stations = [
            'harbor' => $this->station('Harbor Demo Station', 'Beirut', 'Beirut', '33.9010000', '35.5190000'),
            'north' => $this->station('North Demo Station', 'Tripoli', 'North', '34.4367000', '35.8497000'),
            'south' => $this->station('South Demo Station', 'Sidon', 'South', '33.5571000', '35.3729000', active: false),
        ];

        $companies = [
            'atlas' => Company::query()->forceCreate(['name' => 'Atlas Logistics', 'tax_no' => 'LB-DEMO-1001', 'status' => CompanyStatus::Active]),
            'cedar' => Company::query()->forceCreate(['name' => 'Cedar Catering', 'tax_no' => 'LB-DEMO-1002', 'status' => CompanyStatus::Active]),
        ];

        $admin = $this->user('Demo Admin', 'admin@fleetfuel.test', UserRole::Admin, $asOf);
        $managers = [
            'atlas' => $this->user('Atlas Fleet Manager', 'manager.atlas@fleetfuel.test', UserRole::CompanyManager, $asOf, company: $companies['atlas']),
            'cedar' => $this->user('Cedar Fleet Manager', 'manager.cedar@fleetfuel.test', UserRole::CompanyManager, $asOf, company: $companies['cedar']),
        ];
        $operators = [
            'harbor' => $this->user('Harbor Station Operator', 'operator.beirut@fleetfuel.test', UserRole::StationOperator, $asOf, station: $stations['harbor']),
            'north' => $this->user('North Station Operator', 'operator.tripoli@fleetfuel.test', UserRole::StationOperator, $asOf, station: $stations['north']),
        ];

        foreach ($products as $code => $product) {
            $this->price($product, self::PREVIOUS_PRICES[$code], $previousStart->subDay(), $admin);
            $this->price($product, self::CURRENT_PRICES[$code], $currentStart, $admin);
        }

        $this->seedRates($admin, $previousStart, $currentStart, $asOf, $hasCurrentMonth);
        $cards = $this->seedFleet($companies, $products);

        $plan = $this->schedule(self::PREVIOUS_MONTH_PURCHASES, $previousStart, $currentStart);
        if ($hasCurrentMonth) {
            $plan = [...$plan, ...$this->schedule(self::CURRENT_MONTH_PURCHASES, $currentStart, $asOf)];
        } else {
            $this->command->warn('The as-of time is less than two hours into its month; current-month scenarios were skipped.');
        }

        // Record in time order so ledger IDs increase with event time.
        usort($plan, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        foreach ($plan as $index => $purchase) {
            $this->builder->recordPurchase(
                $cards[$purchase['card']],
                $stations[$purchase['station']],
                $operators[$purchase['station']],
                $products[$purchase['product']],
                $purchase['liters'],
                $purchase['at'],
                sprintf('DEMO-%04d', $index + 1),
                $purchase['odometer'],
            );
        }

        if ($hasCurrentMonth) {
            // Quota-reduction scenario: 250 L used, limit cut to 200 L (audited).
            $this->builder->changeMonthlyLimits(
                $cards['FF-ATLAS-H02'],
                $managers['atlas'],
                ['monthly_limit_l' => '200.00'],
                $this->pointIn($currentStart, $asOf, 950),
            );
        }

        $this->seedDeliveries($companies, $admin, $managers, $asOf);
    }

    private function seedRates(User $admin, CarbonImmutable $previousStart, CarbonImmutable $currentStart, CarbonImmutable $asOf, bool $hasCurrentMonth): void
    {
        // One fixture observation per UTC day, each valid for 72 hours,
        // covering all of last month and the 72 hours before the as-of time.
        for ($day = $previousStart->startOfDay()->subDay(); $day->lessThanOrEqualTo($asOf->startOfDay()); $day = $day->addDay()) {
            ExchangeRate::query()->forceCreate([
                'base' => 'USD',
                'quote' => 'LBP',
                'rate' => self::FIXTURE_RATE,
                'source' => RateSource::Fixture,
                'effective_at' => $day,
                'fetched_at' => null,
                'expires_at' => $day->addHours(72),
                'created_by' => null,
                'reason' => null,
                'created_at' => $day,
            ]);
        }

        // Expired-observation scenario: an old provider value that is no
        // longer eligible (and never used in fixture mode anyway).
        $observed = $asOf->subDays(10)->startOfDay();
        ExchangeRate::query()->forceCreate([
            'base' => 'USD',
            'quote' => 'LBP',
            'rate' => '89450.00000000',
            'source' => RateSource::Provider,
            'effective_at' => $observed,
            'fetched_at' => $observed->addMinutes(5),
            'expires_at' => $observed->addHours(72),
            'created_by' => null,
            'reason' => null,
            'created_at' => $observed->addMinutes(5),
        ]);

        if ($hasCurrentMonth) {
            // Admin-override scenario, already expired by the as-of time so the
            // simulator uses the fixture rate.
            $windowSeconds = $asOf->getTimestamp() - $currentStart->getTimestamp();
            $start = $this->pointIn($currentStart, $asOf, 450);
            $this->builder->createManualRate(
                $admin,
                '89700.00000000',
                $start,
                $start->addSeconds(min(24 * 3600, intdiv($windowSeconds * 300, 1000))),
                'Demo scenario: temporary bank-rate adjustment',
            );
        }
    }

    /**
     * @param  array<string, Company>  $companies
     * @param  Collection<string, Product>  $products
     * @return array<string, FuelCard>
     */
    private function seedFleet(array $companies, $products): array
    {
        $vehicles = [];
        foreach (self::VEHICLES as [$plate, $company, $fuelType, $tank, $odometer]) {
            $vehicles[$plate] = Vehicle::query()->forceCreate([
                'company_id' => $companies[$company]->id,
                'plate_no' => $plate,
                'fuel_type' => FuelType::from($fuelType),
                'tank_capacity_l' => $tank,
                'odometer_km' => $odometer,
                'is_active' => true,
            ]);
        }

        $drivers = [];
        foreach (self::DRIVERS as [$key, $company, $name, $license, $phone]) {
            $drivers[$key] = Driver::query()->forceCreate([
                'company_id' => $companies[$company]->id,
                'name' => $name,
                'license_no' => $license,
                'phone' => $phone,
                'is_active' => true,
            ]);
        }

        $cards = [];
        foreach (self::CARDS as [$cardNo, $company, $plate, $driver, $product, $limitL, $limitUsd, $status]) {
            $cards[$cardNo] = FuelCard::query()->forceCreate([
                'company_id' => $companies[$company]->id,
                'vehicle_id' => $plate === null ? null : $vehicles[$plate]->id,
                'driver_id' => $driver === null ? null : $drivers[$driver]->id,
                'card_no' => $cardNo,
                'allowed_product_id' => $product === null ? null : $products[$product]->id,
                'monthly_limit_l' => $limitL,
                'monthly_limit_usd' => $limitUsd,
                'status' => CardStatus::from($status),
            ]);
        }

        return $cards;
    }

    /**
     * Six orders covering every state; two delivered orders feed the SLA report.
     *
     * @param  array<string, Company>  $companies
     * @param  array<string, User>  $managers
     */
    private function seedDeliveries(array $companies, User $admin, array $managers, CarbonImmutable $asOf): void
    {
        $order = $this->delivery($companies['atlas'], $managers['atlas'], 'Atlas depot, Port Road, Beirut (demo)', 'Beirut', '2000.00', $asOf->subDays(9), $asOf->subDays(12));
        $this->scheduleDelivery($order, $admin, $asOf->subDays(11), $asOf->subDays(9), 'TRK-01');
        $this->builder->transitionDelivery($order, DeliveryStatus::OutForDelivery, $admin, $asOf->subDays(9)->addMinutes(15));
        $this->builder->transitionDelivery($order, DeliveryStatus::Delivered, $admin, $asOf->subDays(9)->addHours(2));

        $order = $this->delivery($companies['cedar'], $managers['cedar'], 'Cedar central kitchen, Jdeideh (demo)', 'Mount Lebanon', '1500.00', $asOf->subDays(6), $asOf->subDays(8));
        $this->scheduleDelivery($order, $admin, $asOf->subDays(7), $asOf->subDays(6), 'TRK-02');
        $this->builder->transitionDelivery($order, DeliveryStatus::OutForDelivery, $admin, $asOf->subDays(6)->addMinutes(10));
        $this->builder->transitionDelivery($order, DeliveryStatus::Delivered, $admin, $asOf->subDays(6)->addHours(3));

        $order = $this->delivery($companies['atlas'], $managers['atlas'], 'Atlas north yard, Tripoli (demo)', 'North', '3000.00', $asOf->subHour(), $asOf->subDays(3));
        $this->scheduleDelivery($order, $admin, $asOf->subDays(2), $asOf->subHour(), 'TRK-01');
        $this->builder->transitionDelivery($order, DeliveryStatus::OutForDelivery, $admin, $asOf->subMinutes(45));

        $order = $this->delivery($companies['atlas'], $managers['atlas'], 'Atlas depot, Port Road, Beirut (demo)', 'Beirut', '1200.00', $asOf->addDay(), $asOf->subDay());
        $this->scheduleDelivery($order, $admin, $asOf->subHours(20), $asOf->addDay(), 'TRK-03');

        $this->delivery($companies['atlas'], $managers['atlas'], 'Atlas Bekaa hub, Zahle (demo)', 'Bekaa', '2500.00', $asOf->addDays(2), $asOf->subHours(2));

        $order = $this->delivery($companies['cedar'], $managers['cedar'], 'Cedar central kitchen, Jdeideh (demo)', 'Mount Lebanon', '1000.00', $asOf->subDays(2), $asOf->subDays(5));
        $this->builder->transitionDelivery($order, DeliveryStatus::Cancelled, $managers['cedar'], $asOf->subDays(4), [
            'cancel_reason' => 'Duplicate order placed by mistake',
        ]);
    }

    private function delivery(Company $company, User $creator, string $address, string $governorate, string $liters, CarbonImmutable $preferredStart, CarbonImmutable $createdAt): DeliveryOrder
    {
        return $this->builder->createDelivery($company, $creator, [
            'address' => $address,
            'governorate' => $governorate,
            'liters' => $liters,
            'preferred_start_at' => $preferredStart,
            'preferred_end_at' => $preferredStart->addHours(4),
        ], $createdAt);
    }

    private function scheduleDelivery(DeliveryOrder $order, User $admin, CarbonImmutable $at, CarbonImmutable $windowStart, string $truck): void
    {
        $this->builder->transitionDelivery($order, DeliveryStatus::Scheduled, $admin, $at, [
            'scheduled_start_at' => $windowStart,
            'scheduled_end_at' => $windowStart->addHours(4),
            'assigned_truck' => $truck,
        ]);
    }

    private function station(string $name, string $district, string $governorate, string $latitude, string $longitude, bool $active = true): Station
    {
        return Station::query()->forceCreate([
            'name' => $name,
            'district' => $district,
            'governorate' => $governorate,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'is_active' => $active,
        ]);
    }

    private function user(string $name, string $email, UserRole $role, CarbonImmutable $asOf, ?Company $company = null, ?Station $station = null): User
    {
        return User::query()->forceCreate([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => $asOf,
            // Hashed by the model's "hashed" cast; the plain value lives only in .env.
            'password' => (string) config('fleetfuel.demo.password'),
            'role' => $role,
            'company_id' => $company?->id,
            'station_id' => $station?->id,
            'is_active' => true,
        ]);
    }

    private function price(Product $product, string $priceLbp, CarbonImmutable $effectiveFrom, User $admin): void
    {
        ProductPrice::query()->forceCreate([
            'product_id' => $product->id,
            'price_lbp' => $priceLbp,
            'effective_from' => $effectiveFrom,
            'created_by' => $admin->id,
            'created_at' => $effectiveFrom,
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: ?int, 6?: int}>  $purchases
     * @return list<array{card: string, station: string, product: string, liters: string, odometer: ?int, at: CarbonImmutable}>
     */
    private function schedule(array $purchases, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return array_map(fn (array $purchase): array => [
            'card' => $purchase[0],
            'station' => $purchase[1],
            'product' => $purchase[2],
            'liters' => $purchase[3],
            'odometer' => $purchase[5],
            'at' => $this->pointIn($start, $end, $purchase[4])->addMinutes($purchase[6] ?? 0),
        ], $purchases);
    }

    /**
     * The instant $perMille thousandths of the way from $start to $end, to the
     * minute. Integer arithmetic keeps the result identical on every run.
     */
    private function pointIn(CarbonImmutable $start, CarbonImmutable $end, int $perMille): CarbonImmutable
    {
        $seconds = intdiv(($end->getTimestamp() - $start->getTimestamp()) * $perMille, 1000);

        return $start->addSeconds($seconds)->startOfMinute();
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function counts(): array
    {
        return [
            ['companies', Company::query()->count()],
            ['stations', Station::query()->count()],
            ['users', User::query()->count()],
            ['products', Product::query()->count()],
            ['product_prices', ProductPrice::query()->count()],
            ['exchange_rates', ExchangeRate::query()->count()],
            ['vehicles', Vehicle::query()->count()],
            ['drivers', Driver::query()->count()],
            ['fuel_cards', FuelCard::query()->count()],
            ['fuel_transactions', FuelTransaction::query()->count()],
            ['card_monthly_usage', CardMonthlyUsage::query()->count()],
            ['delivery_orders', DeliveryOrder::query()->count()],
            ['delivery_status_history', DeliveryStatusHistory::query()->count()],
            ['audit_logs', AuditLog::query()->count()],
        ];
    }
}
