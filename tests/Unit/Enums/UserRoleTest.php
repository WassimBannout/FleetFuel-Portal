<?php

namespace Tests\Unit\Enums;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    /** The table in docs/05-API-CONTRACT.md, "Allowed token abilities". */
    public function test_token_abilities_match_the_api_contract(): void
    {
        $this->assertSame([
            'reference:read', 'transactions:read', 'cards:read', 'cards:write',
            'fleet:read', 'fleet:write', 'deliveries:read', 'deliveries:write',
            'deliveries:status', 'reports:read', 'exports:read',
        ], UserRole::Admin->tokenAbilities());

        $this->assertSame([
            'reference:read', 'transactions:read', 'cards:read', 'cards:write',
            'fleet:read', 'fleet:write', 'deliveries:read', 'deliveries:write',
            'reports:read', 'exports:read',
        ], UserRole::CompanyManager->tokenAbilities());

        $this->assertSame([
            'reference:read', 'transactions:create', 'transactions:read', 'cards:read',
        ], UserRole::StationOperator->tokenAbilities());
    }

    public function test_no_role_gets_a_wildcard_and_key_abilities_stay_with_one_role(): void
    {
        foreach (UserRole::cases() as $role) {
            $abilities = $role->tokenAbilities();

            $this->assertNotContains('*', $abilities);
            $this->assertSame($role === UserRole::Admin, in_array('deliveries:status', $abilities, true));
            $this->assertSame($role === UserRole::StationOperator, in_array('transactions:create', $abilities, true));
            $this->assertSame($role !== UserRole::StationOperator, in_array('cards:write', $abilities, true));
        }
    }

    public function test_each_role_has_a_landing_page_and_a_label(): void
    {
        $this->assertSame('dashboard', UserRole::Admin->homeRoute());
        $this->assertSame('dashboard', UserRole::CompanyManager->homeRoute());
        $this->assertSame('station.home', UserRole::StationOperator->homeRoute());
        $this->assertSame('Company manager', UserRole::CompanyManager->label());
    }
}
