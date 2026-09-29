<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Provisioning and disabling accounts. There is no self-registration and no
 * screen for changing roles (D16); an administrator with shell access runs
 * the users:* Artisan commands, which call this service.
 */
class UserAccountService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(
        string $name,
        string $email,
        string $password,
        UserRole $role,
        ?Company $company = null,
        ?Station $station = null,
        ?User $actor = null,
    ): User {
        $this->ensureValidScope($role, $company, $station);

        return DB::transaction(function () use ($name, $email, $password, $role, $company, $station, $actor): User {
            $user = new User([
                'name' => $name,
                'email' => Str::lower(trim($email)),
                'password' => $password,
            ]);

            // Role and tenant are set explicitly, never mass-assigned.
            $user->forceFill([
                'role' => $role,
                'company_id' => $company?->id,
                'station_id' => $station?->id,
                'is_active' => true,
            ])->save();

            $this->audit->record('user.created', $user, $actor, $company?->id, null, [
                'role' => $role->value,
                'company_id' => $company?->id,
                'station_id' => $station?->id,
                'is_active' => true,
            ]);

            return $user;
        });
    }

    /**
     * Disable an account everywhere at once: sign-in is refused, every API
     * token is deleted and every stored browser session ends.
     *
     * @return array{tokens: int, sessions: int}
     */
    public function deactivate(User $user, ?User $actor = null): array
    {
        return DB::transaction(function () use ($user, $actor): array {
            // A new remember token invalidates any "remember me" cookie.
            $user->forceFill(['is_active' => false, 'remember_token' => Str::random(60)])->save();

            $tokens = (int) $user->tokens()->delete();
            $sessions = $this->endSessions($user);

            $this->audit->record('user.deactivated', $user, $actor, $user->company_id,
                ['is_active' => true],
                ['is_active' => false, 'tokens_revoked' => $tokens],
            );

            return ['tokens' => $tokens, 'sessions' => $sessions];
        });
    }

    public function activate(User $user, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $actor): void {
            $user->forceFill(['is_active' => true])->save();

            $this->audit->record('user.activated', $user, $actor, $user->company_id,
                ['is_active' => false],
                ['is_active' => true],
            );
        });
    }

    /**
     * The same rule as the users_role_scope_check constraint, checked first
     * so the caller gets a readable message; plus active tenant checks.
     *
     * @throws InvalidArgumentException
     */
    public function ensureValidScope(UserRole $role, ?Company $company, ?Station $station): void
    {
        match ($role) {
            UserRole::Admin => $company === null && $station === null
                ?: throw new InvalidArgumentException('An admin belongs to no company or station.'),
            UserRole::CompanyManager => $company !== null && $station === null
                ?: throw new InvalidArgumentException('A company manager needs exactly one company and no station.'),
            UserRole::StationOperator => $station !== null && $company === null
                ?: throw new InvalidArgumentException('A station operator needs exactly one station and no company.'),
        };

        if ($company !== null && $company->status !== CompanyStatus::Active) {
            throw new InvalidArgumentException("Company {$company->id} is inactive.");
        }

        if ($station !== null && ! $station->is_active) {
            throw new InvalidArgumentException("Station {$station->id} is inactive.");
        }
    }

    private function endSessions(User $user): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }
}
