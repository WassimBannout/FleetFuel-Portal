<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * role, company_id, station_id and is_active are never mass-assignable:
 * users cannot choose or change their own role or tenant (D16).
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isCompanyManager(): bool
    {
        return $this->role === UserRole::CompanyManager;
    }

    public function isStationOperator(): bool
    {
        return $this->role === UserRole::StationOperator;
    }

    /** True only for the manager of exactly this company. */
    public function managesCompany(int|string|null $companyId): bool
    {
        return $this->isCompanyManager()
            && $companyId !== null
            && (int) $this->company_id === (int) $companyId;
    }

    /** True only for an operator of exactly this station. */
    public function operatesStation(int|string|null $stationId): bool
    {
        return $this->isStationOperator()
            && $stationId !== null
            && (int) $this->station_id === (int) $stationId;
    }
}
