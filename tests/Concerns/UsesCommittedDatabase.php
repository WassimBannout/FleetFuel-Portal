<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestDatabaseGuard;

/**
 * For tests whose work happens in other PHP processes (concurrency workers,
 * a real HTTP server): those processes only see committed rows, so these
 * tests use a dedicated test database without a test-wide transaction.
 * It is migrated once per test class and emptied before every test.
 */
trait UsesCommittedDatabase
{
    /** @var array<string, true> */
    private static array $migratedDatabases = [];

    protected function useCommittedDatabase(string $database): void
    {
        DB::statement('CREATE DATABASE IF NOT EXISTS `'.$database.'`');
        TestDatabaseGuard::assertSafe('testing', 'mysql', $database);

        config(['database.connections.mysql.database' => $database]);
        DB::purge('mysql');

        if (! isset(self::$migratedDatabases[$database])) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            self::$migratedDatabases[$database] = true;

            return;
        }

        Schema::withoutForeignKeyConstraints(function () use ($database): void {
            // Only this database's tables: the MySQL user can see other test databases too.
            foreach (Schema::getTableListing($database, schemaQualified: false) as $table) {
                if ($table !== 'migrations') {
                    DB::table($table)->truncate();
                }
            }
        });
    }

    /**
     * Environment for a PHP child process that must use the same database.
     *
     * @return array<string, string>
     */
    protected function childProcessEnvironment(string $database): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => base_path('bootstrap/cache/config.testing.php'),
            'DB_DATABASE' => $database,
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'EXCHANGE_RATE_MODE' => 'fixture',
        ];
    }
}
