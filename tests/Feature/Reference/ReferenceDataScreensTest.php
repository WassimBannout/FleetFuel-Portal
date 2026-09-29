<?php

namespace Tests\Feature\Reference;

use App\Enums\CompanyStatus;
use App\Enums\ProductCode;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * Companies, stations and products: admins manage them; everyone else only
 * reads active stations and products (T05). Status changes are audited
 * (T08) and nothing can be deleted.
 */
class ReferenceDataScreensTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;
    use SignsInDemoAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
    }

    public function test_an_admin_adds_and_edits_a_company(): void
    {
        $this->actingAs($this->admin());

        $this->post('/companies', ['name' => 'Walkthrough Haulage', 'tax_no' => 'LB-DEMO-2001'])
            ->assertRedirect(route('companies.index'));
        $company = Company::query()->where('name', 'Walkthrough Haulage')->sole();
        $this->assertSame(CompanyStatus::Active, $company->status);

        $this->post('/companies', ['name' => 'Copycat', 'tax_no' => 'LB-DEMO-2001'])->assertSessionHasErrors('tax_no');
        $this->put(route('companies.update', $company), ['name' => 'Walkthrough Haulage SAL', 'tax_no' => 'LB-DEMO-2001'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Walkthrough Haulage SAL', $company->refresh()->name);

        $this->get('/companies')->assertOk()->assertSee('Walkthrough Haulage SAL');
        $this->get(route('companies.edit', $company))->assertOk();
    }

    /** T08: company deactivation is audited. */
    public function test_company_deactivation_and_reactivation_are_audited(): void
    {
        $atlas = $this->atlas();
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->patch(route('companies.status', $atlas), ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertSame(CompanyStatus::Inactive, $atlas->refresh()->status);
        $this->patch(route('companies.status', $atlas), ['status' => 'active'])->assertSessionHasNoErrors();

        $entries = AuditLog::query()->where('auditable_type', 'company')->orderBy('id')->get();
        $this->assertSame(['company.deactivated', 'company.activated'], $entries->pluck('action')->all());
        $this->assertSame(['status' => 'active'], $entries[0]->old_values);
        $this->assertSame(['status' => 'inactive'], $entries[0]->new_values);
        $this->assertSame($admin->id, $entries[0]->user_id);
        $this->assertSame($atlas->id, $entries[0]->company_id);
    }

    /** T05: only admins manage companies. */
    public function test_managers_and_operators_cannot_manage_companies(): void
    {
        $atlas = $this->atlas();

        foreach ([$this->atlasManager(), $this->operator()] as $user) {
            $this->actingAs($user);
            $this->get('/companies')->assertForbidden();
            $this->get('/companies/create')->assertForbidden();
            $this->post('/companies', ['name' => 'Sneaky'])->assertForbidden();
            $this->get(route('companies.edit', $atlas))->assertForbidden();
            $this->patch(route('companies.status', $atlas), ['status' => 'inactive'])->assertForbidden();
        }

        $this->assertSame(CompanyStatus::Active, $atlas->refresh()->status);
        $this->assertFalse(Company::query()->where('name', 'Sneaky')->exists());
    }

    public function test_an_admin_manages_stations_and_deactivation_is_audited(): void
    {
        $this->actingAs($this->admin());

        $this->post('/stations', ['name' => 'East Demo Station', 'district' => 'Zahle', 'governorate' => 'Bekaa', 'latitude' => '91', 'longitude' => '35.9'])
            ->assertSessionHasErrors('latitude');
        $this->post('/stations', ['name' => 'East Demo Station', 'district' => 'Zahle', 'governorate' => 'Bekaa', 'latitude' => '33.84670001', 'longitude' => '35.9'])
            ->assertSessionHasErrors('latitude');
        $this->post('/stations', ['name' => 'East Demo Station', 'district' => 'Zahle', 'governorate' => 'Bekaa', 'latitude' => '33.8467', 'longitude' => '35.9019'])
            ->assertRedirect(route('stations.index'));

        $station = $this->station('East Demo Station');
        $this->assertTrue($station->is_active);
        $this->assertSame('33.8467000', $station->latitude);

        $this->patch(route('stations.active', $station), ['is_active' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($station->refresh()->is_active);
        $this->assertSame(1, AuditLog::query()->where('action', 'station.deactivated')->where('auditable_id', $station->id)->count());
    }

    public function test_managers_and_operators_read_active_stations_only(): void
    {
        $south = $this->station('South Demo Station');
        $this->assertFalse($south->is_active);

        foreach ([$this->atlasManager(), $this->operator()] as $user) {
            $this->actingAs($user);

            $stations = $this->get('/stations')->assertOk()->assertDontSee('South Demo Station')->assertDontSee('Add station')->viewData('stations');
            $this->assertCount(2, $stations);

            $this->get(route('stations.edit', $this->station('Harbor Demo Station')))->assertForbidden();
            $this->patch(route('stations.active', $this->station('Harbor Demo Station')), ['is_active' => '0'])->assertForbidden();
            $this->post('/stations', ['name' => 'Rogue', 'district' => 'X', 'governorate' => 'Y'])->assertForbidden();
        }

        $this->assertTrue($this->station('Harbor Demo Station')->is_active);
        $this->assertFalse(Station::query()->where('name', 'Rogue')->exists());
    }

    public function test_an_admin_renames_and_deactivates_products_but_codes_are_fixed(): void
    {
        $diesel = $this->product(ProductCode::Diesel);
        $this->actingAs($this->admin());

        $this->put(route('products.update', $diesel), ['name' => 'Diesel (gas oil)', 'code' => 'KEROSENE'])
            ->assertSessionHasErrors('code');
        $this->put(route('products.update', $diesel), ['name' => 'Diesel (gas oil)'])->assertSessionHasNoErrors();
        $this->assertSame('Diesel (gas oil)', $diesel->refresh()->name);
        $this->assertSame('DIESEL', $diesel->code);

        $ulp98 = $this->product(ProductCode::Ulp98);
        $this->patch(route('products.active', $ulp98), ['is_active' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::query()->where('action', 'product.deactivated')->count());

        // Non-admins only see active products and cannot change them.
        $this->actingAs($this->atlasManager());
        $codes = $this->get('/products')->assertOk()->viewData('products')->pluck('code')->all();
        $this->assertSame(['DIESEL', 'ULP95'], $codes);
        $this->put(route('products.update', $diesel), ['name' => 'Hacked'])->assertForbidden();
        $this->assertSame('Diesel (gas oil)', $diesel->refresh()->name);
    }

    public function test_reference_data_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());

        $this->delete(route('companies.update', $this->atlas()))->assertStatus(405);
        $this->delete(route('stations.update', $this->station('Harbor Demo Station')))->assertStatus(405);
        $this->delete(route('products.update', $this->product(ProductCode::Diesel)))->assertStatus(405);
    }

    public function test_company_names_are_html_escaped(): void
    {
        $this->actingAs($this->admin())
            ->post('/companies', ['name' => '<img src=x onerror=alert(1)>'])
            ->assertSessionHasNoErrors();

        $this->get('/companies')
            ->assertOk()
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
    }
}
