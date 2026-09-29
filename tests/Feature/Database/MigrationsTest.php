<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Runs only against the isolated fleetfuel_test database (see TestCase).
 */
class MigrationsTest extends TestCase
{
    private const DOMAIN_TABLES = [
        'companies', 'stations', 'products', 'product_prices', 'exchange_rates',
        'integration_sync_states', 'vehicles', 'drivers', 'fuel_cards', 'card_monthly_usage',
        'fuel_transactions', 'delivery_orders', 'delivery_status_history', 'audit_logs',
    ];

    public function test_every_migration_rolls_back_and_reapplies_on_mysql(): void
    {
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->assertTablesExist();

        $this->artisan('migrate:reset')->assertSuccessful();
        // Only this database: the test user can also see fleetfuel_test_concurrency.
        $this->assertSame(['migrations'], Schema::getTableListing(DB::getDatabaseName(), schemaQualified: false));

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTablesExist();
        $this->assertTrue(Schema::hasColumns('users', ['role', 'company_id', 'station_id', 'is_active']));
    }

    private function assertTablesExist(): void
    {
        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }
    }
}
