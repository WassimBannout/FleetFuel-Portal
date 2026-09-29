<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Laravel skips its CSRF check while running tests, so a normal passing
 * form test proves nothing about CSRF. Here the real middleware runs with
 * that shortcut switched off.
 */
class CsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(PreventRequestForgery::class, fn (Application $app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    public function test_a_form_post_without_a_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(419);
        $this->assertGuest();
    }

    public function test_a_cross_site_post_without_a_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Sec-Fetch-Site', 'cross-site')
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(419);
        $this->assertGuest();
    }

    public function test_a_wrong_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->withSession(['_token' => 'token-in-the-session'])
            ->post('/login', ['_token' => 'a-different-token', 'email' => $user->email, 'password' => 'password'])
            ->assertStatus(419);
        $this->assertGuest();
    }

    public function test_the_token_from_the_users_own_session_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->withSession(['_token' => 'token-in-the-session'])
            ->post('/login', ['_token' => 'token-in-the-session', 'email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Laravel 13 also accepts a browser's own "Sec-Fetch-Site: same-origin"
     * header, which a cross-site attacker's page cannot forge.
     */
    public function test_a_same_origin_browser_request_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Sec-Fetch-Site', 'same-origin')
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_signing_out_also_needs_the_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($user);
    }

    /** Bearer-token API requests carry no cookie, so there is nothing to forge. */
    public function test_the_token_api_does_not_use_csrf_tokens(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'csrf-test',
        ])->assertCreated();
    }
}
