<?php

namespace App\Enums;

/**
 * Mutually exclusive roles. A company manager belongs to exactly one company,
 * a station operator to exactly one station, and an admin to neither
 * (enforced by the users_role_scope_check database constraint).
 */
enum UserRole: string
{
    case Admin = 'admin';
    case CompanyManager = 'company_manager';
    case StationOperator = 'station_operator';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::CompanyManager => 'Company manager',
            self::StationOperator => 'Station operator',
        };
    }

    /**
     * The API token abilities for this role (docs/05-API-CONTRACT.md). The
     * server picks them; a client can never ask for more. Abilities only
     * narrow access: policies still check role, ownership and active state.
     *
     * @return list<string>
     */
    public function tokenAbilities(): array
    {
        return match ($this) {
            self::Admin => [
                'reference:read', 'transactions:read', 'cards:read', 'cards:write',
                'fleet:read', 'fleet:write', 'deliveries:read', 'deliveries:write',
                'deliveries:status', 'reports:read', 'exports:read',
            ],
            self::CompanyManager => [
                'reference:read', 'transactions:read', 'cards:read', 'cards:write',
                'fleet:read', 'fleet:write', 'deliveries:read', 'deliveries:write',
                'reports:read', 'exports:read',
            ],
            self::StationOperator => [
                'reference:read', 'transactions:create', 'transactions:read', 'cards:read',
            ],
        };
    }

    /** Route name of the page this role lands on after signing in. */
    public function homeRoute(): string
    {
        return $this === self::StationOperator ? 'station.home' : 'dashboard';
    }
}
