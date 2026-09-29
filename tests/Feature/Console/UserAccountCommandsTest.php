<?php

namespace Tests\Feature\Console;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Station;
use App\Models\User;
use App\Services\CredentialVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The provisioning path (D16): users:create, users:deactivate and
 * users:activate. There is no self-registration and no role-editing screen.
 */
class UserAccountCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-local-password';

    private const ASK = 'Password (at least 12 characters)';

    private const REPEAT = 'Repeat the password';

    public function test_creates_a_company_manager_with_a_hidden_password(): void
    {
        $company = Company::factory()->create();

        $this->artisan('users:create', [
            'email' => 'New.Manager@Example.test',
            '--name' => 'New Manager',
            '--role' => 'company_manager',
            '--company' => (string) $company->id,
        ])
            ->expectsQuestion(self::ASK, self::PASSWORD)
            ->expectsQuestion(self::REPEAT, self::PASSWORD)
            ->expectsOutputToContain('Created Company manager new.manager@example.test')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertSuccessful();

        $user = User::query()->where('email', 'new.manager@example.test')->sole();
        $this->assertSame(UserRole::CompanyManager, $user->role);
        $this->assertSame($company->id, $user->company_id);
        $this->assertNull($user->station_id);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotNull(app(CredentialVerifier::class)->verify('new.manager@example.test', self::PASSWORD));

        $audit = AuditLog::query()->where('action', 'user.created')->sole();
        $this->assertSame('user', $audit->auditable_type);
        $this->assertSame($user->id, $audit->auditable_id);
        $this->assertNull($audit->user_id, 'created from the command line, not by a signed-in user');
        // MySQL's JSON type stores object keys in its own order, so compare sorted.
        $expected = ['company_id' => $company->id, 'is_active' => true, 'role' => 'company_manager', 'station_id' => null];
        $actual = (array) $audit->new_values;
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode($audit->new_values));
    }

    public function test_creates_a_station_operator(): void
    {
        $station = Station::factory()->create();

        $this->artisan('users:create', [
            'email' => 'operator.new@example.test',
            '--name' => 'New Operator',
            '--role' => 'station_operator',
            '--station' => (string) $station->id,
        ])
            ->expectsQuestion(self::ASK, self::PASSWORD)
            ->expectsQuestion(self::REPEAT, self::PASSWORD)
            ->assertSuccessful();

        $user = User::query()->where('email', 'operator.new@example.test')->sole();
        $this->assertSame(UserRole::StationOperator, $user->role);
        $this->assertSame($station->id, $user->station_id);
        $this->assertNull($user->company_id);
    }

    public function test_refuses_a_role_with_the_wrong_company_or_station_before_asking_for_a_password(): void
    {
        $company = Company::factory()->create();
        $station = Station::factory()->create();

        $cases = [
            'manager without a company' => ['--role' => 'company_manager'],
            'manager with a station' => ['--role' => 'company_manager', '--company' => (string) $company->id, '--station' => (string) $station->id],
            'operator with a company' => ['--role' => 'station_operator', '--company' => (string) $company->id],
            'admin with a company' => ['--role' => 'admin', '--company' => (string) $company->id],
        ];

        foreach ($cases as $options) {
            $this->artisan('users:create', ['email' => 'someone@example.test', '--name' => 'Someone'] + $options)
                ->assertFailed();
        }

        $this->assertSame(0, User::query()->count());
    }

    public function test_refuses_an_inactive_company_or_station(): void
    {
        $company = Company::factory()->create(['status' => CompanyStatus::Inactive]);
        $station = Station::factory()->create(['is_active' => false]);

        $this->artisan('users:create', ['email' => 'a@example.test', '--name' => 'A', '--role' => 'company_manager', '--company' => (string) $company->id])
            ->expectsOutputToContain('is inactive')
            ->assertFailed();
        $this->artisan('users:create', ['email' => 'b@example.test', '--name' => 'B', '--role' => 'station_operator', '--station' => (string) $station->id])
            ->expectsOutputToContain('is inactive')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_refuses_a_duplicate_email_an_unknown_role_or_a_missing_company(): void
    {
        $existing = User::factory()->admin()->create(['email' => 'taken@example.test']);

        $this->artisan('users:create', ['email' => 'Taken@Example.test', '--name' => 'X', '--role' => 'admin'])->assertFailed();
        $this->artisan('users:create', ['email' => 'new@example.test', '--name' => 'X', '--role' => 'superuser'])->assertFailed();
        $this->artisan('users:create', ['email' => 'new@example.test', '--name' => 'X', '--role' => 'company_manager', '--company' => '999999'])->assertFailed();

        $this->assertSame([$existing->id], User::query()->pluck('id')->all());
    }

    public function test_refuses_a_short_or_mismatched_password(): void
    {
        $arguments = ['email' => 'admin.two@example.test', '--name' => 'Second Admin', '--role' => 'admin'];

        $this->artisan('users:create', $arguments)
            ->expectsQuestion(self::ASK, 'short')
            ->expectsQuestion(self::REPEAT, 'short')
            ->assertFailed();

        $this->artisan('users:create', $arguments)
            ->expectsQuestion(self::ASK, self::PASSWORD)
            ->expectsQuestion(self::REPEAT, 'something-else-entirely')
            ->expectsOutputToContain('The passwords do not match.')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_never_runs_without_the_hidden_password_prompt(): void
    {
        $this->artisan('users:create', [
            'email' => 'admin.two@example.test',
            '--name' => 'Second Admin',
            '--role' => 'admin',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Run this command interactively')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_deactivating_blocks_sign_in_revokes_tokens_and_is_audited(): void
    {
        $manager = User::factory()->create();
        $manager->createToken('laptop');
        $manager->createToken('phone');
        $rememberToken = $manager->remember_token;

        $this->artisan('users:deactivate', ['email' => strtoupper($manager->email)])
            ->expectsOutputToContain('revoked 2 API token(s)')
            ->assertSuccessful();

        $manager->refresh();
        $this->assertFalse($manager->is_active);
        $this->assertSame(0, $manager->tokens()->count());
        $this->assertNotSame($rememberToken, $manager->remember_token);
        $this->assertNull(app(CredentialVerifier::class)->verify($manager->email, 'password'));

        $audit = AuditLog::query()->where('action', 'user.deactivated')->sole();
        $this->assertSame(['is_active' => true], $audit->old_values);
        $this->assertSame(['is_active' => false, 'tokens_revoked' => 2], $audit->new_values);
        $this->assertSame($manager->company_id, $audit->company_id);

        // Running it again changes nothing.
        $this->artisan('users:deactivate', ['email' => $manager->email])
            ->expectsOutputToContain('already disabled')
            ->assertSuccessful();
        $this->assertSame(1, AuditLog::query()->where('action', 'user.deactivated')->count());
    }

    public function test_reactivating_restores_sign_in_but_not_revoked_tokens(): void
    {
        $manager = User::factory()->inactive()->create();

        $this->artisan('users:activate', ['email' => $manager->email])->assertSuccessful();

        $this->assertTrue($manager->refresh()->is_active);
        $this->assertNotNull(app(CredentialVerifier::class)->verify($manager->email, 'password'));
        $this->assertSame(0, $manager->tokens()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'user.activated')->count());
    }

    public function test_an_unknown_email_is_reported(): void
    {
        $this->artisan('users:deactivate', ['email' => 'nobody@example.test'])
            ->expectsOutputToContain('No account has that email.')
            ->assertFailed();
        $this->artisan('users:activate', ['email' => 'nobody@example.test'])->assertFailed();
    }
}
