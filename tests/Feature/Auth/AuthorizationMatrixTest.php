<?php

namespace Tests\Feature\Auth;

use App\Enums\DeliveryStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\ExchangeRate;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\TestCase;

/**
 * The role/tenant matrix of docs/01-PROJECT-BRIEF.md for the scoped query
 * helpers and the policies, with two companies and two stations (seeded
 * demo). Pages and endpoints that use them are tested on their routes in
 * TenantIsolationTest and the Api tests; later milestones add theirs.
 */
class AuthorizationMatrixTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;

    private Company $atlas;

    private Company $cedar;

    private Station $harbor;

    private User $admin;

    private User $managerAtlas;

    private User $managerCedar;

    private User $operatorHarbor;

    private User $operatorNorth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();

        $this->atlas = Company::query()->where('name', 'Atlas Logistics')->firstOrFail();
        $this->cedar = Company::query()->where('name', 'Cedar Catering')->firstOrFail();
        $this->harbor = Station::query()->where('name', 'Harbor Demo Station')->firstOrFail();
        $this->admin = $this->account('admin@fleetfuel.test');
        $this->managerAtlas = $this->account('manager.atlas@fleetfuel.test');
        $this->managerCedar = $this->account('manager.cedar@fleetfuel.test');
        $this->operatorHarbor = $this->account('operator.beirut@fleetfuel.test');
        $this->operatorNorth = $this->account('operator.tripoli@fleetfuel.test');
    }

    public function test_company_owned_records_are_scoped_by_role(): void
    {
        $models = [
            'vehicles' => [fn () => Vehicle::query(), fn (User $user) => Vehicle::query()->visibleTo($user)],
            'drivers' => [fn () => Driver::query(), fn (User $user) => Driver::query()->visibleTo($user)],
            'fuel cards' => [fn () => FuelCard::query(), fn (User $user) => FuelCard::query()->visibleTo($user)],
            'delivery orders' => [fn () => DeliveryOrder::query(), fn (User $user) => DeliveryOrder::query()->visibleTo($user)],
        ];

        foreach ($models as $label => [$all, $visibleTo]) {
            $this->assertTrue($all()->where('company_id', $this->atlas->id)->exists(), "{$label}: fixture needs Atlas rows");
            $this->assertTrue($all()->where('company_id', $this->cedar->id)->exists(), "{$label}: fixture needs Cedar rows");

            $this->assertSameRows($all(), $visibleTo($this->admin), "{$label}: admin sees all");
            $this->assertSameRows($all()->where('company_id', $this->atlas->id), $visibleTo($this->managerAtlas), "{$label}: Atlas manager");
            $this->assertSameRows($all()->where('company_id', $this->cedar->id), $visibleTo($this->managerCedar), "{$label}: Cedar manager");
            $this->assertSame(0, $visibleTo($this->operatorHarbor)->count(), "{$label}: operators see none");
        }
    }

    public function test_purchases_are_scoped_by_company_for_managers_and_by_station_for_operators(): void
    {
        $this->assertSameRows(FuelTransaction::query(), FuelTransaction::query()->visibleTo($this->admin), 'admin');
        $this->assertSameRows(
            FuelTransaction::query()->where('company_id', $this->atlas->id),
            FuelTransaction::query()->visibleTo($this->managerAtlas),
            'Atlas manager',
        );
        $this->assertSameRows(
            FuelTransaction::query()->where('station_id', $this->harbor->id),
            FuelTransaction::query()->visibleTo($this->operatorHarbor),
            'Harbor operator',
        );
        $this->assertSameRows(
            FuelTransaction::query()->where('station_id', $this->operatorNorth->station_id),
            FuelTransaction::query()->visibleTo($this->operatorNorth),
            'North operator',
        );
        $this->assertGreaterThan(0, FuelTransaction::query()->visibleTo($this->operatorNorth)->count());
    }

    public function test_companies_reference_data_and_audit_logs_are_scoped_by_role(): void
    {
        $this->assertSame(2, Company::query()->visibleTo($this->admin)->count());
        $this->assertSame([$this->atlas->id], Company::query()->visibleTo($this->managerAtlas)->pluck('id')->all());
        $this->assertSame(0, Company::query()->visibleTo($this->operatorHarbor)->count());

        // The seeded South Demo Station is inactive: only the admin sees it.
        $this->assertSame(3, Station::query()->visibleTo($this->admin)->count());
        $this->assertSame(2, Station::query()->visibleTo($this->managerAtlas)->count());
        $this->assertSame(2, Station::query()->visibleTo($this->operatorHarbor)->count());
        $this->assertSame(Product::query()->where('is_active', true)->count(), Product::query()->visibleTo($this->managerAtlas)->count());

        $this->assertSame(AuditLog::query()->count(), AuditLog::query()->visibleTo($this->admin)->count());
        $this->assertSame(0, AuditLog::query()->visibleTo($this->managerAtlas)->count());
        $this->assertSame(0, AuditLog::query()->visibleTo($this->operatorHarbor)->count());
    }

    public function test_purchase_policy_follows_company_for_managers_and_station_for_operators(): void
    {
        $actors = [$this->admin, $this->managerAtlas, $this->managerCedar, $this->operatorHarbor, $this->operatorNorth];

        foreach (FuelTransaction::query()->get() as $transaction) {
            foreach ($actors as $user) {
                $expected = $user->isAdmin()
                    || ($user->isCompanyManager() && $user->company_id === $transaction->company_id)
                    || ($user->isStationOperator() && $user->station_id === $transaction->station_id);

                $this->assertSame($expected, $user->can('view', $transaction), "{$user->email} viewing purchase {$transaction->id}");
                // Accepted purchases are immutable for everyone.
                $this->assertFalse($user->can('update', $transaction));
                $this->assertFalse($user->can('delete', $transaction));
            }
        }

        $this->assertTrue($this->operatorHarbor->can('create', FuelTransaction::class));
        $this->assertFalse($this->managerAtlas->can('create', FuelTransaction::class));
        $this->assertFalse($this->admin->can('create', FuelTransaction::class));
    }

    /** T05: an operator cannot change quotas; a manager only their own company's cards. */
    public function test_card_changes_are_limited_to_the_admin_and_the_owning_manager(): void
    {
        $atlasCard = FuelCard::query()->where('company_id', $this->atlas->id)->firstOrFail();
        $cedarCard = FuelCard::query()->where('company_id', $this->cedar->id)->firstOrFail();

        $this->assertTrue($this->admin->can('update', $atlasCard));
        $this->assertTrue($this->admin->can('update', $cedarCard));
        $this->assertTrue($this->managerAtlas->can('update', $atlasCard));
        $this->assertFalse($this->managerAtlas->can('update', $cedarCard));
        $this->assertFalse($this->managerAtlas->can('view', $cedarCard));

        foreach ([$this->operatorHarbor, $this->operatorNorth] as $operator) {
            $this->assertFalse($operator->can('update', $atlasCard));
            $this->assertFalse($operator->can('viewAny', FuelCard::class));
            $this->assertFalse($operator->can('create', FuelCard::class));
            $this->assertFalse($operator->can('create', Vehicle::class));
            $this->assertFalse($operator->can('create', Driver::class));
        }

        $vehicle = Vehicle::query()->where('company_id', $this->cedar->id)->firstOrFail();
        $this->assertFalse($this->managerAtlas->can('update', $vehicle));
        $this->assertTrue($this->managerCedar->can('update', $vehicle));
    }

    /** T05: a manager cannot write prices; reference data and FX are admin-only. */
    public function test_only_an_admin_manages_prices_reference_data_rates_and_audit(): void
    {
        foreach ([$this->managerAtlas, $this->managerCedar, $this->operatorHarbor] as $user) {
            $this->assertTrue($user->can('viewAny', ProductPrice::class), $user->email);
            $this->assertFalse($user->can('create', ProductPrice::class), $user->email);
            $this->assertFalse($user->can('create', Station::class));
            $this->assertFalse($user->can('update', $this->harbor));
            $this->assertFalse($user->can('create', Company::class));
            $this->assertFalse($user->can('update', $this->atlas));
            $this->assertFalse($user->can('create', ExchangeRate::class));
            $this->assertFalse($user->can('viewAny', ExchangeRate::class));
            $this->assertFalse($user->can('viewAny', AuditLog::class));
        }

        foreach (['create' => ProductPrice::class, 'viewAny' => AuditLog::class] as $ability => $class) {
            $this->assertTrue($this->admin->can($ability, $class));
        }
        $this->assertTrue($this->admin->can('create', ExchangeRate::class));
        $this->assertTrue($this->admin->can('update', $this->harbor));
    }

    public function test_delivery_actions_follow_the_matrix(): void
    {
        $atlasPending = DeliveryOrder::query()->where('company_id', $this->atlas->id)->where('status', DeliveryStatus::Pending)->firstOrFail();
        $atlasInProgress = DeliveryOrder::query()->where('company_id', $this->atlas->id)
            ->whereIn('status', [DeliveryStatus::Scheduled, DeliveryStatus::OutForDelivery])->firstOrFail();
        $finished = DeliveryOrder::query()->whereIn('status', [DeliveryStatus::Delivered, DeliveryStatus::Cancelled])->firstOrFail();
        $cedarOrder = DeliveryOrder::query()->where('company_id', $this->cedar->id)->firstOrFail();

        $this->assertTrue($this->managerAtlas->can('cancel', $atlasPending));
        $this->assertFalse($this->managerAtlas->can('cancel', $atlasInProgress), 'managers cancel pending orders only');
        $this->assertFalse($this->managerAtlas->can('advance', $atlasPending));
        $this->assertFalse($this->managerAtlas->can('view', $cedarOrder));
        $this->assertFalse($this->managerAtlas->can('cancel', $cedarOrder));

        $this->assertTrue($this->admin->can('advance', $atlasPending));
        $this->assertTrue($this->admin->can('cancel', $atlasInProgress));
        $this->assertFalse($this->admin->can('cancel', $finished), 'a finished order cannot be cancelled');

        $this->assertFalse($this->operatorHarbor->can('viewAny', DeliveryOrder::class));
        $this->assertFalse($this->operatorHarbor->can('create', DeliveryOrder::class));
    }

    public function test_a_disabled_account_is_denied_everything(): void
    {
        $this->admin->forceFill(['is_active' => false])->save();
        $transaction = FuelTransaction::query()->firstOrFail();

        $this->assertFalse($this->admin->can('viewAny', Company::class));
        $this->assertFalse($this->admin->can('view', $transaction));
        $this->assertFalse($this->admin->can('create', ProductPrice::class));
        $this->assertFalse($this->admin->can('viewAny', FuelTransaction::class));
    }

    public function test_role_and_tenant_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        User::query()->create([
            'name' => 'Escalation Attempt',
            'email' => 'escalation@fleetfuel.test',
            'password' => 'irrelevant-password',
            'role' => UserRole::Admin,
        ]);
    }

    public function test_the_login_form_ignores_an_injected_role_or_company(): void
    {
        $this->post('/login', [
            'email' => $this->managerAtlas->email,
            'password' => self::DEMO_PASSWORD,
            'role' => UserRole::Admin->value,
            'company_id' => $this->cedar->id,
        ])->assertRedirect(route('dashboard'));

        $manager = $this->managerAtlas->fresh();
        $this->assertNotNull($manager);
        $this->assertSame(UserRole::CompanyManager, $manager->role);
        $this->assertSame($this->atlas->id, $manager->company_id);
        $this->get('/dashboard')->assertOk()->assertDontSee('Cedar Catering');
    }

    /**
     * @param  Builder<covariant Model>  $expected
     * @param  Builder<covariant Model>  $actual
     */
    private function assertSameRows(Builder $expected, Builder $actual, string $label): void
    {
        $this->assertSame(
            $expected->orderBy('id')->pluck('id')->all(),
            $actual->orderBy('id')->pluck('id')->all(),
            $label,
        );
    }

    private function account(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
