<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_tests_run_on_the_isolated_mysql_test_database(): void
    {
        $this->assertSame('fleetfuel_test', DB::scalar('select database()'));
        $this->assertStringStartsWith('8.4.', (string) DB::scalar('select version()'));
    }

    public function test_the_test_account_cannot_see_the_development_database(): void
    {
        $databases = array_column(DB::select('show databases'), 'Database');

        $this->assertContains('fleetfuel_test', $databases);
        $this->assertNotContains('fleetfuel', $databases);
    }
}
