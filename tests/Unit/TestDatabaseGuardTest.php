<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    #[DataProvider('safeDatabases')]
    public function test_it_allows_the_isolated_mysql_test_database(string $database): void
    {
        TestDatabaseGuard::assertSafe('testing', 'mysql', $database);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function safeDatabases(): array
    {
        return [
            'test database' => ['fleetfuel_test'],
            'parallel worker database' => ['fleetfuel_test_3'],
        ];
    }

    #[DataProvider('unsafeTargets')]
    public function test_it_refuses_any_other_target(string $environment, string $driver, string $database): void
    {
        $this->expectException(RuntimeException::class);

        TestDatabaseGuard::assertSafe($environment, $driver, $database);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function unsafeTargets(): array
    {
        return [
            'development database' => ['testing', 'mysql', 'fleetfuel'],
            'lookalike database name' => ['testing', 'mysql', 'fleetfuel_testing'],
            'local environment' => ['local', 'mysql', 'fleetfuel_test'],
            'sqlite in memory' => ['testing', 'sqlite', ':memory:'],
        ];
    }
}
