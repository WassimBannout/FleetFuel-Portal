<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_readiness_reports_ok_when_the_database_answers(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'checks' => ['app' => 'ok', 'database' => 'ok'],
            ]);
    }

    public function test_readiness_returns_503_without_details_when_the_database_is_down(): void
    {
        $log = Log::spy();
        $this->breakDatabaseConnection();

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'unavailable',
                'checks' => ['app' => 'ok', 'database' => 'unavailable'],
            ]);

        // The failure reason is kept for operators in the server log.
        $log->shouldHaveReceived('warning')->once();
    }

    public function test_readiness_runs_without_session_middleware(): void
    {
        // A database-backed session would itself fail while MySQL is down.
        $this->get('/health')->assertCookieMissing((string) config('session.cookie'));
    }

    public function test_liveness_does_not_depend_on_the_database(): void
    {
        $this->breakDatabaseConnection();

        $this->get('/up')->assertOk();
    }

    private function breakDatabaseConnection(): void
    {
        // Nothing listens on port 1, so connecting fails immediately.
        config([
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => 1,
        ]);
        DB::purge('mysql');
    }
}
