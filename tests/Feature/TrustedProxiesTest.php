<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * Behind an HTTPS proxy the app receives plain HTTP from the proxy. Its
 * X-Forwarded-* headers are believed only from the addresses in
 * TRUSTED_PROXIES (config fleetfuel.trusted_proxies); anyone else could
 * fake them. Laravel's test teardown resets the trusted list.
 */
class TrustedProxiesTest extends TestCase
{
    private const FORWARDED = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'fleetfuel.example.com', 'X-Forwarded-Port' => '443'];

    public function test_forwarded_headers_are_ignored_by_default(): void
    {
        $this->assertSignInFormPostsTo('http://localhost/login', '10.1.2.3');
    }

    public function test_only_the_configured_proxies_are_believed(): void
    {
        $this->trustProxies('10.0.0.0/8, 192.0.2.10');

        $this->assertSignInFormPostsTo('https://fleetfuel.example.com/login', '10.1.2.3');
        $this->assertSignInFormPostsTo('https://fleetfuel.example.com/login', '192.0.2.10');
        $this->assertSignInFormPostsTo('http://localhost/login', '203.0.113.9');
    }

    private function trustProxies(string $setting): void
    {
        config(['fleetfuel.trusted_proxies' => $setting]);
        (new AppServiceProvider($this->app))->boot();
    }

    private function assertSignInFormPostsTo(string $action, string $clientAddress): void
    {
        // An absolute URL: the test client would otherwise build it from the
        // previous request's (forwarded) host and scheme.
        $this->withServerVariables(['REMOTE_ADDR' => $clientAddress])
            ->get('http://localhost/login', self::FORWARDED)
            ->assertOk()
            ->assertSee('action="'.$action.'"', false);
    }
}
