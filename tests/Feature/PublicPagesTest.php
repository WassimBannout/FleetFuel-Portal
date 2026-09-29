<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    public function test_home_page_shows_the_project_shell(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertViewIs('home')
            ->assertSee('FleetFuel Portal')
            ->assertSee('fictional companies')
            ->assertSee(route('login'));
    }

    /**
     * Fortify is installed, but only its login and logout routes are
     * registered: no self-registration, password reset, profile, password
     * confirmation or two-factor endpoints (D16).
     */
    #[DataProvider('absentFortifyRoutes')]
    public function test_fortify_extra_routes_do_not_exist(string $method, string $uri): void
    {
        $this->call($method, $uri)->assertNotFound();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function absentFortifyRoutes(): array
    {
        return [
            'register page' => ['GET', '/register'],
            'register submit' => ['POST', '/register'],
            'forgot password' => ['GET', '/forgot-password'],
            'reset link request' => ['POST', '/forgot-password'],
            'reset password' => ['POST', '/reset-password'],
            'profile update' => ['PUT', '/user/profile-information'],
            'password update' => ['PUT', '/user/password'],
            'password confirmation' => ['GET', '/user/confirm-password'],
            'two-factor challenge' => ['GET', '/two-factor-challenge'],
            'passkey login' => ['POST', '/passkeys/login'],
            'sanctum spa cookie' => ['GET', '/sanctum/csrf-cookie'],
        ];
    }
}
