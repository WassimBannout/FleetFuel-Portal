<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\FuelTransaction;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Redact;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\TestCase;

/**
 * T04/T05 on the implemented web pages, with the seeded demo: two companies
 * (Atlas, Cedar), two operated stations (Harbor, North) and 32 purchases.
 */
class TenantIsolationTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;

    /** The Beirut quota month containing FIXTURE_NOW. */
    private const MONTH = '2026-09-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
    }

    public function test_a_manager_dashboard_counts_and_lists_only_their_company(): void
    {
        $atlas = $this->company('Atlas Logistics');

        $response = $this->actingAs($this->account('manager.atlas@fleetfuel.test'))->get('/dashboard')->assertOk();

        $recent = $response->viewData('recent');
        $this->assertNotEmpty($recent);
        foreach ($recent as $transaction) {
            $this->assertSame($atlas->id, $transaction->company_id);
        }

        $ownThisMonth = FuelTransaction::query()->where('company_id', $atlas->id)->where('quota_month', self::MONTH);
        $totals = $response->viewData('current');
        $this->assertSame($ownThisMonth->count(), $totals['purchases']);
        $this->assertSame((string) $ownThisMonth->sum('liters'), $totals['liters']);
        $this->assertSame(
            Vehicle::query()->where('company_id', $atlas->id)->where('is_active', true)->count(),
            $response->viewData('vehicles'),
        );
        $this->assertNull($response->viewData('companies'));

        $response->assertDontSee('Cedar Catering');
        FuelTransaction::query()->where('company_id', '!=', $atlas->id)->pluck('id')
            ->each(fn (int $id) => $response->assertDontSee('href="'.route('transactions.show', $id).'"', false));
    }

    public function test_the_admin_dashboard_covers_every_company(): void
    {
        $response = $this->actingAs($this->account('admin@fleetfuel.test'))->get('/dashboard')->assertOk();

        $this->assertSame(2, $response->viewData('companies'));
        $this->assertSame(
            FuelTransaction::query()->where('quota_month', self::MONTH)->count(),
            $response->viewData('current')['purchases'],
        );
    }

    public function test_a_manager_cannot_open_another_companys_purchase_by_guessing_its_id(): void
    {
        foreach (['manager.atlas@fleetfuel.test' => 'Atlas Logistics', 'manager.cedar@fleetfuel.test' => 'Cedar Catering'] as $email => $name) {
            $company = $this->company($name);
            $own = FuelTransaction::query()->where('company_id', $company->id)->firstOrFail();
            $foreign = FuelTransaction::query()->where('company_id', '!=', $company->id)->firstOrFail();

            $this->actingAs($this->account($email));

            $this->get(route('transactions.show', $own->id))->assertOk()->assertSee($own->external_ref);
            $this->get(route('transactions.show', $foreign->id))->assertNotFound()->assertDontSee($foreign->external_ref);
            // A foreign ID looks exactly like an ID that does not exist.
            $this->get(route('transactions.show', 999999))->assertNotFound();
        }
    }

    public function test_an_operator_sees_only_purchases_made_at_their_own_station(): void
    {
        $harbor = Station::query()->where('name', 'Harbor Demo Station')->firstOrFail();

        $response = $this->actingAs($this->account('operator.beirut@fleetfuel.test'))->get('/station')->assertOk();

        $purchases = $response->viewData('purchases');
        $this->assertNotEmpty($purchases);
        foreach ($purchases as $transaction) {
            $this->assertSame($harbor->id, $transaction->station_id);
        }

        $atHarbor = FuelTransaction::query()->where('station_id', $harbor->id)->firstOrFail();
        $elsewhere = FuelTransaction::query()->where('station_id', '!=', $harbor->id)->firstOrFail();

        $this->get(route('transactions.show', $atHarbor->id))->assertOk();
        $this->get(route('transactions.show', $elsewhere->id))->assertNotFound();
    }

    public function test_an_admin_can_open_any_purchase(): void
    {
        $this->actingAs($this->account('admin@fleetfuel.test'));

        foreach (['Atlas Logistics', 'Cedar Catering'] as $name) {
            $transaction = FuelTransaction::query()->where('company_id', $this->company($name)->id)->firstOrFail();
            $this->get(route('transactions.show', $transaction->id))->assertOk()->assertSee($name);
        }
    }

    public function test_each_role_is_kept_out_of_the_other_roles_area(): void
    {
        $this->actingAs($this->account('operator.beirut@fleetfuel.test'))->get('/dashboard')->assertForbidden();
        $this->actingAs($this->account('manager.atlas@fleetfuel.test'))->get('/station')->assertForbidden();
        $this->actingAs($this->account('admin@fleetfuel.test'))->get('/station')->assertForbidden();
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        foreach (['/dashboard', '/station', '/transactions/1'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    /**
     * Every route needs a signed-in user (web) or a token (API), except a
     * short public list, so a new route without `auth` fails here. Each
     * route is requested as a guest, with 1 for every route parameter.
     */
    public function test_every_route_except_the_public_ones_requires_sign_in(): void
    {
        $public = ['GET /', 'GET /login', 'POST /login', 'GET /up', 'GET /health', 'POST /api/v1/auth/token'];
        $seen = [];
        $refused = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $uri = '/'.ltrim($route->uri(), '/');
                $seen[] = "{$method} {$uri}";
                if (in_array("{$method} {$uri}", $public, true)) {
                    continue;
                }

                $api = str_starts_with($uri, '/api/');
                $response = $this->call($method, (string) preg_replace('/\{[^}]+\}/', '1', $uri), server: $api ? ['HTTP_ACCEPT' => 'application/json'] : []);

                $protected = $api ? $response->getStatusCode() === 401 : $response->isRedirect(route('login'));
                $this->assertTrue($protected, "{$method} {$uri} answered a guest with HTTP {$response->getStatusCode()}.");
                $refused[] = "{$method} {$uri}";
            }
        }

        $this->assertSame([], array_values(array_diff($public, $seen)), 'A public route in the list no longer exists.');
        $this->assertGreaterThan(80, count($refused));
    }

    public function test_names_are_html_escaped(): void
    {
        Station::query()->where('name', 'Harbor Demo Station')->firstOrFail()
            ->update(['name' => '<script>alert("x")</script>']);

        $this->actingAs($this->account('operator.beirut@fleetfuel.test'))
            ->get('/station')
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("x")</script>', false);
    }

    public function test_lists_show_masked_card_numbers(): void
    {
        $response = $this->actingAs($this->account('admin@fleetfuel.test'))->get('/dashboard')->assertOk();

        foreach ($response->viewData('recent') as $transaction) {
            $response->assertDontSee($transaction->fuelCard->card_no, false);
            $response->assertSee(Redact::cardNumber($transaction->fuelCard->card_no), false);
        }
    }

    private function account(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function company(string $name): Company
    {
        return Company::query()->where('name', $name)->firstOrFail();
    }
}
