<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T03 through the real routes and Fortify's pipeline: sign-in, sign-out,
 * session regeneration, one generic failure message, login throttling and
 * disabled accounts.
 */
class WebLoginTest extends TestCase
{
    use RefreshDatabase;

    /** UserFactory's password for every generated user. */
    private const PASSWORD = 'password';

    public function test_login_page_is_a_csrf_protected_form_without_registration(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertViewIs('auth.login')
            ->assertSee('name="_token"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertDontSee('href="'.url('/register').'"', false)
            ->assertDontSee('Forgot', false);
    }

    #[DataProvider('landingPages')]
    public function test_each_role_signs_in_to_its_own_landing_page(string $state, string $route): void
    {
        $user = User::factory()->{$state}()->create();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route($route));

        $this->assertAuthenticatedAs($user);
        $this->get(route($route))->assertOk();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function landingPages(): array
    {
        return [
            'admin' => ['admin', 'dashboard'],
            'company manager' => ['companyManager', 'dashboard'],
            'station operator' => ['stationOperator', 'station.home'],
        ];
    }

    public function test_email_is_case_insensitive(): void
    {
        $user = User::factory()->create(['email' => 'manager.test@fleetfuel.test']);

        $this->post('/login', ['email' => 'Manager.Test@FleetFuel.test', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_signing_in_replaces_the_session_id(): void
    {
        $user = User::factory()->create();
        $cookie = (string) config('session.cookie');

        $before = $this->get('/login')->getCookie($cookie)?->getValue();
        $this->assertNotNull($before);

        $after = $this->withCookie($cookie, $before)
            ->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->getCookie($cookie)?->getValue();

        $this->assertNotNull($after);
        $this->assertNotSame($before, $after, 'The pre-login session ID must not survive sign-in (session fixation).');
    }

    public function test_wrong_password_unknown_email_and_disabled_account_fail_identically(): void
    {
        $active = User::factory()->create();
        $disabled = User::factory()->inactive()->create();

        $attempts = [
            'wrong password' => ['email' => $active->email, 'password' => 'not-the-password'],
            'unknown email' => ['email' => 'nobody@fleetfuel.test', 'password' => self::PASSWORD],
            'disabled account, correct password' => ['email' => $disabled->email, 'password' => self::PASSWORD],
        ];

        foreach ($attempts as $case => $credentials) {
            $response = $this->from('/login')->post('/login', $credentials);

            $response->assertRedirect('/login')->assertSessionHasErrors(['email' => __('auth.failed')]);
            $this->assertSame([__('auth.failed')], session('errors')->get('email'), $case);
            $this->assertGuest();
        }
    }

    public function test_a_failed_sign_in_is_logged_without_the_password_or_the_full_email(): void
    {
        $log = Log::spy();
        $user = User::factory()->create(['email' => 'manager.atlas@fleetfuel.test']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Secret-Guess-123']);

        $log->shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            $logged = (string) json_encode($context);

            return $message === 'Sign-in failed.'
                && $context['email'] === 'm***@fleetfuel.test'
                && ! str_contains($logged, 'Secret-Guess-123')
                && ! str_contains($logged, 'manager.atlas');
        })->once();
    }

    public function test_five_failed_attempts_lock_that_email_and_ip_for_a_minute(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->from('/login')
                ->post('/login', ['email' => $user->email, 'password' => "wrong-{$attempt}"])
                ->assertSessionHasErrors(['email' => __('auth.failed')]);
        }

        // Locked: even the correct password is refused.
        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();

        // The lock is per email + IP: another account signs in normally.
        $this->post('/login', ['email' => $other->email, 'password' => self::PASSWORD])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($other);
        $this->post('/logout');

        // After the lockout window the correct password works again.
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->signInLikeABrowser(User::factory()->create());

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();

        $this->startNewRequestCycle();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_an_account_disabled_after_sign_in_is_signed_out_on_its_next_request(): void
    {
        $manager = User::factory()->create();
        $this->signInLikeABrowser($manager);

        $this->startNewRequestCycle();
        $this->get('/dashboard')->assertOk();

        app(UserAccountService::class)->deactivate(User::query()->findOrFail($manager->id));

        $this->startNewRequestCycle();
        $this->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'Your account has been disabled. Contact an administrator.');
        $this->assertGuest();
    }

    public function test_a_signed_in_user_opening_the_login_page_goes_to_their_own_page(): void
    {
        $operator = User::factory()->stationOperator()->create();

        $this->actingAs($operator)->get('/login')->assertRedirect(route('station.home'));
    }

    /**
     * Sign in through the form and keep sending the session cookie, like a
     * browser, so later requests are authenticated by the stored session.
     */
    private function signInLikeABrowser(User $user): void
    {
        $cookie = (string) config('session.cookie');

        $response = $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $response->assertRedirect();

        $sessionId = $response->getCookie($cookie)?->getValue();
        $this->assertNotNull($sessionId);

        $this->withCookie($cookie, $sessionId);
    }
}
