<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * T06 and the token contract: POST/DELETE /api/v1/auth/token, abilities,
 * expiry, revocation, disabled accounts, throttling and token-only access.
 */
class TokenAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password';

    /** Test-only probe that reports who a bearer token authenticates. */
    private const WHO_AM_I = '/api/v1/testing/who-am-i';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-28T09:00:00Z'));

        // The real API has no read-only authenticated endpoint yet (M05+).
        // This route lets a test check a token without revoking it.
        Route::middleware(['api', 'auth:sanctum'])->get(self::WHO_AM_I, fn (Request $request) => [
            'id' => $request->user()?->getAuthIdentifier(),
        ]);
    }

    public function test_a_token_is_issued_once_with_the_role_abilities_and_a_24_hour_expiry(): void
    {
        $operator = User::factory()->stationOperator()->create(['name' => 'Harbor Operator']);

        $response = $this->issue($operator->email, device: 'pos-terminal-1')->assertCreated();

        $data = $response->json('data');
        $this->assertSame(['token', 'expires_at', 'user'], array_keys($data));
        $this->assertSame(['id' => $operator->id, 'name' => 'Harbor Operator', 'role' => 'station_operator'], $data['user']);
        $this->assertSame('2026-09-29T09:00:00Z', $data['expires_at']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $stored = PersonalAccessToken::query()->sole();
        $this->assertSame('pos-terminal-1', $stored->name);
        $this->assertSame(UserRole::StationOperator->tokenAbilities(), $stored->abilities);
        $this->assertTrue(CarbonImmutable::parse('2026-09-29T09:00:00Z')->equalTo($stored->expires_at));

        // Only a SHA-256 hash is stored; the plaintext exists in this response only.
        [, $secret] = explode('|', $data['token'], 2);
        $this->assertSame(hash('sha256', $secret), $stored->token);

        $this->withToken($data['token'])->getJson(self::WHO_AM_I)->assertOk()->assertJson(['id' => $operator->id]);
    }

    public function test_each_role_gets_exactly_its_documented_abilities(): void
    {
        foreach (['admin', 'companyManager', 'stationOperator'] as $state) {
            $user = User::factory()->{$state}()->create();

            $this->issue($user->email)->assertCreated();

            $this->assertSame($user->role->tokenAbilities(), $user->tokens()->sole()->abilities, $state);
        }
    }

    public function test_a_client_cannot_ask_for_abilities_or_a_role(): void
    {
        $manager = User::factory()->create();

        $this->postJson('/api/v1/auth/token', [
            'email' => $manager->email,
            'password' => self::PASSWORD,
            'device_name' => 'escalation-attempt',
            'abilities' => ['*'],
            'role' => 'admin',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.abilities.0', 'This field is not allowed.')
            ->assertJsonPath('error.details.role.0', 'This field is not allowed.');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_wrong_password_unknown_email_and_disabled_account_get_the_same_401(): void
    {
        $active = User::factory()->create();
        $disabled = User::factory()->inactive()->create();

        $errors = [];
        foreach ([[$active->email, 'wrong-password'], ['nobody@fleetfuel.test', self::PASSWORD], [$disabled->email, self::PASSWORD]] as [$email, $password]) {
            $error = $this->issue($email, $password)->assertUnauthorized()->json('error');
            unset($error['request_id']);
            $errors[] = $error;
        }

        $this->assertSame(['code' => 'invalid_credentials', 'message' => __('auth.failed'), 'details' => []], $errors[0]);
        $this->assertSame($errors[0], $errors[1]);
        $this->assertSame($errors[0], $errors[2]);
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_missing_or_invalid_fields_are_a_422_with_field_details(): void
    {
        $this->postJson('/api/v1/auth/token', [])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['message', 'details' => ['email', 'password', 'device_name'], 'request_id']]);

        $user = User::factory()->create();
        $this->issue($user->email, device: str_repeat('d', 81))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['device_name']]]);
    }

    public function test_token_requests_are_limited_to_five_a_minute_per_email_and_ip(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->issue($user->email, 'wrong-password')->assertUnauthorized();
        }

        $limited = $this->issue($user->email)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $retryAfter = (int) $limited->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);

        // The limit is per email + IP, so another account is unaffected.
        $this->issue($other->email)->assertCreated();

        $this->travel(61)->seconds();
        $this->issue($user->email)->assertCreated();
    }

    public function test_revoking_deletes_only_the_current_token(): void
    {
        $manager = User::factory()->create();
        $revoked = $this->issue($manager->email, device: 'laptop')->json('data.token');
        $kept = $this->issue($manager->email, device: 'phone')->json('data.token');

        $this->withToken($revoked)->deleteJson('/api/v1/auth/token')->assertNoContent();

        $this->startNewRequestCycle();
        $this->withToken($revoked)->getJson(self::WHO_AM_I)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->startNewRequestCycle();
        $this->withToken($kept)->getJson(self::WHO_AM_I)->assertOk();
        $this->assertSame(['phone'], $manager->tokens()->pluck('name')->all());
    }

    public function test_missing_malformed_and_unknown_tokens_are_rejected(): void
    {
        $this->getJson(self::WHO_AM_I)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');

        foreach (['not-a-token', '999|'.str_repeat('a', 48), '1|wrong'] as $token) {
            $this->startNewRequestCycle();
            $this->withToken($token)->getJson(self::WHO_AM_I)->assertUnauthorized();
        }
    }

    public function test_a_token_stops_working_after_24_hours(): void
    {
        $token = $this->issue(User::factory()->create()->email)->json('data.token');

        $this->travelTo(CarbonImmutable::parse('2026-09-29T08:59:59Z'));
        $this->withToken($token)->getJson(self::WHO_AM_I)->assertOk();

        $this->startNewRequestCycle();
        $this->travelTo(CarbonImmutable::parse('2026-09-29T09:00:01Z'));
        $this->withToken($token)->getJson(self::WHO_AM_I)->assertUnauthorized();
    }

    public function test_a_disabled_accounts_existing_token_is_rejected(): void
    {
        $manager = User::factory()->create();
        $token = $this->issue($manager->email)->json('data.token');
        $this->withToken($token)->getJson(self::WHO_AM_I)->assertOk();

        // Disabled directly (the token row still exists): Sanctum still refuses it.
        $manager->forceFill(['is_active' => false])->save();

        $this->startNewRequestCycle();
        $this->withToken($token)->getJson(self::WHO_AM_I)->assertUnauthorized();
        $this->assertSame(1, PersonalAccessToken::query()->count());
    }

    public function test_a_browser_session_alone_cannot_use_the_api(): void
    {
        $manager = User::factory()->create();

        $this->post('/login', ['email' => $manager->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($manager, 'web');

        $this->getJson(self::WHO_AM_I)->assertUnauthorized();
        $this->deleteJson('/api/v1/auth/token')->assertUnauthorized();
    }

    /**
     * @return TestResponse<Response>
     */
    private function issue(string $email, string $password = self::PASSWORD, string $device = 'phpunit'): TestResponse
    {
        return $this->postJson('/api/v1/auth/token', [
            'email' => $email,
            'password' => $password,
            'device_name' => $device,
        ]);
    }
}
