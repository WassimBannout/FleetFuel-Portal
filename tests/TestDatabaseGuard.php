<?php

namespace Tests;

use RuntimeException;

/**
 * Refuses to run tests anywhere except the dedicated MySQL test database.
 *
 * Feature tests reset tables, so pointing them at the development database
 * (for example through a stale config cache or a missing .env.testing) would
 * destroy local data. This check runs before any database trait.
 */
final class TestDatabaseGuard
{
    public const TEST_DATABASE = 'fleetfuel_test';

    public static function assertSafe(string $environment, string $driver, string $database): void
    {
        if ($environment !== 'testing') {
            throw new RuntimeException("Tests must run with APP_ENV=testing, not [{$environment}].");
        }

        if ($driver !== 'mysql') {
            throw new RuntimeException("Tests must use MySQL, not the [{$driver}] driver.");
        }

        // Derived names such as fleetfuel_test_1 are used by parallel test runs.
        $isTestDatabase = $database === self::TEST_DATABASE
            || str_starts_with($database, self::TEST_DATABASE.'_');

        if (! $isTestDatabase) {
            throw new RuntimeException(
                "Refusing to run tests against database [{$database}]; expected [".self::TEST_DATABASE.'].'
            );
        }
    }
}
