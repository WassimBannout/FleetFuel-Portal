<?php

namespace Tests\Feature\Fleet;

use App\Enums\CompanyStatus;
use App\Enums\FuelType;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * Vehicle and driver screens on real routes, with the seeded demo
 * (Atlas: ATL-101..105, Cedar: CED-201..203).
 */
class VehicleAndDriverScreensTest extends TestCase
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

    public function test_a_manager_adds_a_vehicle_to_their_own_company(): void
    {
        $this->actingAs($this->atlasManager())
            ->post('/vehicles', ['plate_no' => '  atl   999 ', 'fuel_type' => 'diesel', 'tank_capacity_l' => '65.5', 'odometer_km' => '1200'])
            ->assertRedirect(route('vehicles.index'))
            ->assertSessionHas('status', 'Vehicle ATL 999 added.');

        $vehicle = Vehicle::query()->where('plate_no', 'ATL 999')->sole();
        $this->assertSame($this->atlas()->id, $vehicle->company_id);
        $this->assertSame(FuelType::Diesel, $vehicle->fuel_type);
        $this->assertSame('65.50', $vehicle->tank_capacity_l);
        $this->assertSame(1200, $vehicle->odometer_km);
        $this->assertTrue($vehicle->is_active);
    }

    public function test_the_owning_company_comes_from_the_account_or_an_admins_choice(): void
    {
        $this->actingAs($this->atlasManager())
            ->post('/vehicles', ['company_id' => $this->cedar()->id, 'plate_no' => 'X 1', 'fuel_type' => 'diesel', 'tank_capacity_l' => '50'])
            ->assertSessionHasErrors('company_id');

        $this->actingAs($this->admin());
        $this->post('/vehicles', ['plate_no' => 'X 2', 'fuel_type' => 'diesel', 'tank_capacity_l' => '50'])
            ->assertSessionHasErrors(['company_id' => 'Choose a company.']);
        $this->post('/vehicles', ['company_id' => $this->cedar()->id, 'plate_no' => 'X 3', 'fuel_type' => 'petrol', 'tank_capacity_l' => '50'])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->cedar()->id, Vehicle::query()->where('plate_no', 'X 3')->sole()->company_id);
        $this->assertFalse(Vehicle::query()->whereIn('plate_no', ['X 1', 'X 2'])->exists());
    }

    public function test_vehicle_input_is_validated(): void
    {
        $this->actingAs($this->atlasManager());
        $valid = ['plate_no' => 'NEW 1', 'fuel_type' => 'diesel', 'tank_capacity_l' => '60'];

        // Plates are unique after normalizing ("atl-101" is ATL-101).
        $this->post('/vehicles', ['plate_no' => 'atl-101'] + $valid)->assertSessionHasErrors('plate_no');
        $this->post('/vehicles', ['plate_no' => '<b>X</b>'] + $valid)->assertSessionHasErrors('plate_no');
        $this->post('/vehicles', ['fuel_type' => 'kerosene'] + $valid)->assertSessionHasErrors('fuel_type');
        foreach (['0', '-1', '1e2', '60.123', ''] as $capacity) {
            $this->post('/vehicles', ['tank_capacity_l' => $capacity] + $valid)->assertSessionHasErrors('tank_capacity_l');
        }
        $this->post('/vehicles', ['odometer_km' => '-5'] + $valid)->assertSessionHasErrors('odometer_km');

        $this->assertFalse(Vehicle::query()->where('plate_no', 'NEW 1')->exists());
    }

    public function test_fuel_type_and_company_are_fixed_after_creation(): void
    {
        $vehicle = $this->vehicle('ATL-101');
        $this->actingAs($this->atlasManager());

        $this->put(route('vehicles.update', $vehicle), ['plate_no' => 'ATL-101', 'tank_capacity_l' => '60', 'fuel_type' => 'petrol'])
            ->assertSessionHasErrors(['fuel_type' => 'The fuel type is fixed when the vehicle is created.']);
        $this->put(route('vehicles.update', $vehicle), ['plate_no' => 'ATL-101', 'tank_capacity_l' => '60', 'company_id' => $this->cedar()->id])
            ->assertSessionHasErrors('company_id');

        $this->put(route('vehicles.update', $vehicle), ['plate_no' => 'ATL-101B', 'tank_capacity_l' => '62', 'odometer_km' => '45000'])
            ->assertSessionHasNoErrors();

        $vehicle->refresh();
        $this->assertSame('ATL-101B', $vehicle->plate_no);
        $this->assertSame('62.00', $vehicle->tank_capacity_l);
        $this->assertSame(FuelType::Diesel, $vehicle->fuel_type);
        $this->assertSame($this->atlas()->id, $vehicle->company_id);
    }

    /** T04: another company's vehicle or driver is not found; operators are refused. */
    public function test_fleet_records_are_isolated_by_company_and_role(): void
    {
        $foreignVehicle = $this->vehicle('CED-201');
        $foreignDriver = $this->driver('CED-DL-2001');
        $this->actingAs($this->atlasManager());

        $this->get(route('vehicles.edit', $foreignVehicle))->assertNotFound();
        $this->put(route('vehicles.update', $foreignVehicle), ['plate_no' => 'HIJACK', 'tank_capacity_l' => '1'])->assertNotFound();
        $this->patch(route('vehicles.active', $foreignVehicle), ['is_active' => '0'])->assertNotFound();
        $this->get(route('drivers.edit', $foreignDriver))->assertNotFound();
        $this->patch(route('drivers.active', $foreignDriver), ['is_active' => '0'])->assertNotFound();
        $this->assertTrue($foreignVehicle->refresh()->is_active);
        $this->assertSame('CED-201', $foreignVehicle->plate_no);

        foreach (['/vehicles', '/vehicles?company='.$this->cedar()->id] as $url) {
            foreach ($this->get($url)->assertOk()->viewData('vehicles') as $vehicle) {
                $this->assertSame($this->atlas()->id, $vehicle->company_id, $url);
            }
        }
        foreach ($this->get('/drivers?company='.$this->cedar()->id)->assertOk()->viewData('drivers') as $driver) {
            $this->assertSame($this->atlas()->id, $driver->company_id);
        }

        $this->actingAs($this->operator());
        $this->get('/vehicles')->assertForbidden();
        $this->get('/drivers')->assertForbidden();
        $this->get(route('vehicles.edit', $this->vehicle('ATL-101')))->assertForbidden();
    }

    public function test_search_text_is_matched_literally(): void
    {
        $this->actingAs($this->atlasManager());

        $this->assertCount(5, $this->get('/vehicles')->viewData('vehicles'));
        $this->assertCount(1, $this->get('/vehicles?q=atl-103')->viewData('vehicles'));
        // "%" and "_" are not wildcards: no Atlas plate contains them.
        $this->assertCount(0, $this->get('/vehicles?q=%25')->viewData('vehicles'));
        $this->assertCount(0, $this->get('/vehicles?q=ATL_10')->viewData('vehicles'));
    }

    /** T08: deactivation and reactivation are audited. */
    public function test_deactivating_and_reactivating_is_audited(): void
    {
        $vehicle = $this->vehicle('ATL-102');
        $driver = $this->driver('ATL-DL-1002');
        $manager = $this->atlasManager();
        $this->actingAs($manager);

        $this->patch(route('vehicles.active', $vehicle), ['is_active' => '0'])->assertSessionHasNoErrors();
        $this->patch(route('vehicles.active', $vehicle), ['is_active' => '1'])->assertSessionHasNoErrors();
        $this->patch(route('drivers.active', $driver), ['is_active' => '0'])->assertSessionHasNoErrors();

        $this->assertTrue($vehicle->refresh()->is_active);
        $this->assertFalse($driver->refresh()->is_active);

        $entries = AuditLog::query()->orderBy('id')->get()->filter(
            fn (AuditLog $entry) => str_starts_with($entry->action, 'vehicle.') || str_starts_with($entry->action, 'driver.'),
        )->values();
        $this->assertSame(['vehicle.deactivated', 'vehicle.activated', 'driver.deactivated'], $entries->pluck('action')->all());
        $this->assertSame(['is_active' => true], $entries[0]->old_values);
        $this->assertSame(['is_active' => false], $entries[0]->new_values);
        $this->assertSame($manager->id, $entries[0]->user_id);
        $this->assertSame($this->atlas()->id, $entries[0]->company_id);
    }

    public function test_an_inactive_companys_fleet_is_read_only_except_deactivation(): void
    {
        $this->atlas()->forceFill(['status' => CompanyStatus::Inactive])->save();
        $inactive = BusinessRuleViolation::companyInactive()->getMessage();
        $vehicle = $this->vehicle('ATL-102');
        $this->actingAs($this->atlasManager());

        $this->post('/vehicles', ['plate_no' => 'NEW 2', 'fuel_type' => 'diesel', 'tank_capacity_l' => '60'])
            ->assertSessionHasErrors(['rule' => $inactive]);
        $this->put(route('vehicles.update', $vehicle), ['plate_no' => 'ATL-102', 'tank_capacity_l' => '99'])
            ->assertSessionHasErrors(['rule' => $inactive]);
        $this->post('/drivers', ['name' => 'New Driver', 'license_no' => 'ATL-DL-9999'])
            ->assertSessionHasErrors(['rule' => $inactive]);

        $this->patch(route('vehicles.active', $vehicle), ['is_active' => '0'])->assertSessionHasNoErrors();
        $this->patch(route('vehicles.active', $vehicle), ['is_active' => '1'])->assertSessionHasErrors(['rule' => $inactive]);

        $this->assertFalse($vehicle->refresh()->is_active);
        $this->assertSame('60.00', $vehicle->tank_capacity_l);
        $this->assertFalse(Vehicle::query()->where('plate_no', 'NEW 2')->exists());
    }

    public function test_driver_license_numbers_are_unique_per_company(): void
    {
        $this->actingAs($this->atlasManager())
            ->post('/drivers', ['name' => 'Duplicate', 'license_no' => ' atl-dl-1001 '])
            ->assertSessionHasErrors(['license_no' => 'This company already has a driver with this license number.']);

        // The same license number in another company is a different driver.
        $this->actingAs($this->admin())
            ->post('/drivers', ['company_id' => $this->cedar()->id, 'name' => 'Cedar Namesake', 'license_no' => 'atl-dl-1001', 'phone' => '+961 01 234 567'])
            ->assertSessionHasNoErrors();

        $driver = Driver::query()->where('name', 'Cedar Namesake')->sole();
        $this->assertSame('ATL-DL-1001', $driver->license_no);
        $this->assertSame($this->cedar()->id, $driver->company_id);

        $this->post('/drivers', ['company_id' => $this->cedar()->id, 'name' => 'Bad Phone', 'license_no' => 'X-1', 'phone' => 'call me'])
            ->assertSessionHasErrors('phone');
    }

    public function test_names_are_html_escaped(): void
    {
        $this->actingAs($this->atlasManager())
            ->post('/drivers', ['name' => '<script>alert("x")</script>', 'license_no' => 'XSS-1'])
            ->assertSessionHasNoErrors();

        $this->get('/drivers')
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("x")</script>', false);
    }

    public function test_vehicles_and_drivers_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());

        $this->delete(route('vehicles.update', $this->vehicle('ATL-101')))->assertStatus(405);
        $this->delete(route('drivers.update', $this->driver('ATL-DL-1001')))->assertStatus(405);
    }

    public function test_pages_render_for_admins_and_managers(): void
    {
        foreach ([$this->admin(), $this->atlasManager()] as $user) {
            $this->actingAs($user);

            foreach (['/vehicles', '/vehicles/create', route('vehicles.edit', $this->vehicle('ATL-101')),
                '/drivers', '/drivers/create', route('drivers.edit', $this->driver('ATL-DL-1001'))] as $url) {
                $this->get($url)->assertOk();
            }
        }

        // The admin's form offers active companies; the manager's form names their own.
        $this->actingAs($this->admin())->get('/vehicles/create')->assertSee('Cedar Catering');
        $this->actingAs($this->atlasManager())->get('/vehicles/create')->assertSee('Atlas Logistics')->assertDontSee('Cedar Catering');
    }
}
