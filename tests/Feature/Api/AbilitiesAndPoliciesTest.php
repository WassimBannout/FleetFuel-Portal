<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\FuelCard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\TestCase;

/**
 * T06 "missing ability or wrong role 403" and "token abilities are never
 * sufficient". The card PATCH endpoint arrives in M06, so this test
 * registers a stand-in route wired the way real API routes will be. The
 * checks run in this order (bootstrap/app.php puts the middleware ahead of
 * route binding):
 *
 *   1. role middleware              (role:admin,company_manager)  -> 403
 *   2. token ability middleware     (abilities:cards:write)       -> 403
 *   3. tenant-scoped {card} binding (visibleTo, M03)              -> 404
 *   4. record policy                (update)                      -> 403
 */
class AbilitiesAndPoliciesTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;

    private const URI = '/api/v1/testing/cards/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();

        Route::middleware(['api', 'auth:sanctum', 'role:admin,company_manager', 'abilities:cards:write'])
            ->patch(self::URI.'{card}', function (FuelCard $card): JsonResponse {
                // {card} arrives already tenant-scoped: another company's card is a 404.
                Gate::authorize('update', $card);

                return response()->json(['data' => ['id' => $card->id]]);
            });
    }

    public function test_a_manager_token_reaches_own_cards_but_another_companys_card_is_not_found(): void
    {
        $token = $this->tokenFor('manager.atlas@fleetfuel.test');

        $this->withToken($token)->patchJson(self::URI.$this->card('Atlas Logistics')->id)->assertOk();

        $this->startNewRequestCycle();
        $this->withToken($token)->patchJson(self::URI.$this->card('Cedar Catering')->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_an_operator_token_lacks_the_ability(): void
    {
        $token = $this->tokenFor('operator.beirut@fleetfuel.test');

        $this->withToken($token)->patchJson(self::URI.$this->card('Atlas Logistics')->id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_a_token_with_every_ability_is_still_limited_by_the_role(): void
    {
        // A token wrongly granted all abilities, e.g. created by hand in tinker.
        $operator = User::query()->where('email', 'operator.beirut@fleetfuel.test')->firstOrFail();
        $token = $operator->createToken('forged', ['*'])->plainTextToken;

        $this->withToken($token)->patchJson(self::URI.$this->card('Atlas Logistics')->id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_a_manager_token_without_the_ability_is_refused(): void
    {
        $manager = User::query()->where('email', 'manager.atlas@fleetfuel.test')->firstOrFail();
        $token = $manager->createToken('read-only', ['cards:read'])->plainTextToken;

        $this->withToken($token)->patchJson(self::URI.$this->card('Atlas Logistics')->id)->assertForbidden();

        // The ability check runs before the tenant-scoped lookup, so even
        // another company's card answers 403 here rather than 404.
        $this->startNewRequestCycle();
        $this->withToken($token)->patchJson(self::URI.$this->card('Cedar Catering')->id)->assertForbidden();
    }

    public function test_an_admin_token_reaches_every_company(): void
    {
        $token = $this->tokenFor('admin@fleetfuel.test');

        foreach (['Atlas Logistics', 'Cedar Catering'] as $company) {
            $this->startNewRequestCycle();
            $this->withToken($token)->patchJson(self::URI.$this->card($company)->id)->assertOk();
        }
    }

    public function test_a_disabled_managers_token_is_unauthenticated(): void
    {
        $token = $this->tokenFor('manager.atlas@fleetfuel.test');
        User::query()->where('email', 'manager.atlas@fleetfuel.test')->firstOrFail()->forceFill(['is_active' => false])->save();

        $this->withToken($token)->patchJson(self::URI.$this->card('Atlas Logistics')->id)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    private function tokenFor(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/token', [
            'email' => $email,
            'password' => self::DEMO_PASSWORD,
            'device_name' => 'phpunit',
        ])->assertCreated()->json('data.token');
    }

    private function card(string $companyName): FuelCard
    {
        $company = Company::query()->where('name', $companyName)->firstOrFail();

        return FuelCard::query()->where('company_id', $company->id)->firstOrFail();
    }
}
