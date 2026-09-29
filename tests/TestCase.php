<?php

namespace Tests;

use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application, then stop before any test touches a database
     * other than the isolated MySQL test database.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = config('database.default');

        TestDatabaseGuard::assertSafe(
            $app->environment(),
            (string) config("database.connections.{$connection}.driver"),
            (string) config("database.connections.{$connection}.database"),
        );

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Pages render without a Vite manifest, so tests never depend on `make build`.
        $this->withoutVite();

        // No test reaches the network: an HTTP call that is not faked fails.
        Http::preventStrayRequests();
    }

    /**
     * Within one test Laravel reuses resolved auth guards, and with them the
     * user they already found. A real HTTP request starts from scratch, so a
     * test that signs in, revokes a token or disables an account and then
     * sends another request calls this first.
     */
    protected function startNewRequestCycle(): void
    {
        $this->app->make(AuthManager::class)->forgetGuards();
    }
}
