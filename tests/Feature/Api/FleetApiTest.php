<?php

namespace Tests\Feature\Api;

use App\Enums\CompanyStatus;
use App\Models\Driver;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\CallsApi;
use Tests\Concerns\ChecksOpenApiContract;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * GET/POST /api/v1/vehicles and /api/v1/drivers on the demo seed: tenant
 * scope (T04), server-chosen company (T07), roles and abilities (T06),
 * validation and the documented shapes (T24).
 */
class FleetApiTest extends TestCase
{
    use BuildsLedgerFixtures;
    use CallsApi;
    use ChecksOpenApiContract;
    use RefreshDatabase;
    use SignsInDemoAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
    }

    public function test_a_manager_lists_only_their_companys_vehicles_and_drivers(): void
    {
        $token = $this->apiToken($this->atlasManager());

        $vehicles = $this->fleet('GET', '/vehicles', '', $token)->assertOk();
        $this->assertSame(['ATL-101', 'ATL-102', 'ATL-103', 'ATL-104', 'ATL-105'], $vehicles->json('data.*.plate_no'));
        $this->assertSame([$this->atlas()->id], array_values(array_unique($vehicles->json('data.*.company_id'))));
        $vehicles->assertJsonPath('data.0', [
            'id' => $this->vehicle('ATL-101')->id,
            'company_id' => $this->atlas()->id,
            'plate_no' => 'ATL-101',
            'fuel_type' => 'diesel',
            'tank_capacity_l' => '60.00',
            'odometer_km' => 44800,
            'is_active' => true,
        ]);

        $drivers = $this->fleet('GET', '/drivers', '', $token)->assertOk();
        $this->assertSame(['ATL-DL-1001', 'ATL-DL-1002', 'ATL-DL-1003', 'ATL-DL-1004', 'ATL-DL-1005'], $drivers->json('data.*.license_no'));
        $drivers->assertJsonPath('data.0.name', 'Rami Aoun')->assertJsonPath('data.0.phone', '+961 00 100 001');
    }

    public function test_an_admin_lists_every_company_filters_by_company_and_paginates(): void
    {
        $token = $this->apiToken($this->admin());

        $this->fleet('GET', '/vehicles', '', $token)->assertOk()->assertJsonPath('meta.total', 8);
        $this->assertSame(['CED-201', 'CED-202', 'CED-203'], $this->fleet('GET', '/vehicles', '?company_id='.$this->cedar()->id, $token)->json('data.*.plate_no'));
        $this->fleet('GET', '/drivers', '?company_id='.$this->cedar()->id, $token)->assertOk()->assertJsonPath('meta.total', 3);

        $page = $this->fleet('GET', '/vehicles', '?per_page=3&page=3', $token)->assertOk();
        $this->assertSame(['CED-202', 'CED-203'], $page->json('data.*.plate_no'));
        $page->assertJsonPath('meta.last_page', 3)->assertJsonPath('links.next', null);
    }

    public function test_managers_cannot_choose_a_company_and_operators_are_refused(): void
    {
        $manager = $this->apiToken($this->atlasManager());
        $operator = $this->apiToken($this->operator());

        $this->fleet('GET', '/vehicles', '?company_id='.$this->atlas()->id, $manager)->assertUnprocessable()->assertJsonValidationErrors('company_id', 'error.details');
        $this->fleet('GET', '/drivers', '?company_id='.$this->cedar()->id, $manager)->assertUnprocessable();

        foreach (['/vehicles', '/drivers'] as $path) {
            $this->fleet('GET', $path, '', $operator)->assertForbidden();
            $this->fleet('POST', $path, '', $operator, ['name' => 'X'])->assertForbidden();
            $this->fleet('GET', $path, '', null)->assertUnauthorized();
            // A read-only fleet token cannot create.
            $this->fleet('POST', $path, '', $this->apiToken($this->atlasManager(), ['fleet:read']), ['name' => 'X'])->assertForbidden();
        }
    }

    public function test_a_manager_creates_a_vehicle_in_their_own_company(): void
    {
        $response = $this->fleet('POST', '/vehicles', '', $this->apiToken($this->atlasManager()), [
            'plate_no' => ' atl  901 ',
            'fuel_type' => 'diesel',
            'tank_capacity_l' => '75.5',
            'odometer_km' => 12000,
        ])->assertCreated();

        $vehicle = Vehicle::query()->where('plate_no', 'ATL 901')->sole();
        $response->assertExactJson(['data' => [
            'id' => $vehicle->id,
            'company_id' => $this->atlas()->id,
            'plate_no' => 'ATL 901',
            'fuel_type' => 'diesel',
            'tank_capacity_l' => '75.50',
            'odometer_km' => 12000,
            'is_active' => true,
        ]]);
    }

    public function test_vehicle_input_is_validated_strictly(): void
    {
        $token = $this->apiToken($this->atlasManager());
        $valid = ['plate_no' => 'ATL-950', 'fuel_type' => 'petrol', 'tank_capacity_l' => '50.00'];
        $before = Vehicle::query()->count();

        $refused = [
            'company_id' => ['company_id' => $this->atlas()->id],
            'plate_no' => ['plate_no' => 'atl-101'], // taken, after normalizing
            'fuel_type' => ['fuel_type' => 'electric'],
            'tank_capacity_l' => ['tank_capacity_l' => 50],
            'odometer_km' => ['odometer_km' => '12000'],
            'is_active' => ['is_active' => false],
        ];

        foreach ($refused as $field => $change) {
            $this->fleet('POST', '/vehicles', '', $token, $change + $valid)->assertUnprocessable()->assertJsonValidationErrors($field, 'error.details');
        }

        $this->fleet('POST', '/vehicles', '', $token, ['tank_capacity_l' => '0'] + $valid)->assertUnprocessable();
        $this->fleet('POST', '/vehicles', '', $token, ['fuel_type' => 'diesel'])->assertUnprocessable()->assertJsonValidationErrors(['plate_no', 'tank_capacity_l'], 'error.details');
        $this->assertSame($before, Vehicle::query()->count());
    }

    public function test_an_admin_must_name_an_active_company_as_a_json_integer(): void
    {
        $token = $this->apiToken($this->admin());
        $valid = ['plate_no' => 'CED-990', 'fuel_type' => 'diesel', 'tank_capacity_l' => '90.00'];
        $cedar = $this->cedar();

        $this->fleet('POST', '/vehicles', '', $token, $valid)->assertUnprocessable()->assertJsonValidationErrors('company_id', 'error.details');
        $this->fleet('POST', '/vehicles', '', $token, ['company_id' => (string) $cedar->id] + $valid)->assertUnprocessable();

        $cedar->forceFill(['status' => CompanyStatus::Inactive])->save();
        $this->fleet('POST', '/vehicles', '', $token, ['company_id' => $cedar->id] + $valid)->assertUnprocessable();

        $cedar->forceFill(['status' => CompanyStatus::Active])->save();
        $this->fleet('POST', '/vehicles', '', $token, ['company_id' => $cedar->id] + $valid)
            ->assertCreated()
            ->assertJsonPath('data.company_id', $cedar->id);
    }

    public function test_drivers_are_created_with_a_license_unique_within_the_company(): void
    {
        $manager = $this->apiToken($this->atlasManager());

        $this->fleet('POST', '/drivers', '', $manager, ['name' => 'Nadine Haddad', 'license_no' => ' atl-dl-9001 ', 'phone' => null])
            ->assertCreated()
            ->assertJsonPath('data.license_no', 'ATL-DL-9001')
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.company_id', $this->atlas()->id)
            ->assertJsonPath('data.is_active', true);

        $this->fleet('POST', '/drivers', '', $manager, ['name' => 'Someone Else', 'license_no' => 'ATL-DL-1001'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.license_no.0', 'This company already has a driver with this license number.');
        $this->fleet('POST', '/drivers', '', $manager, ['name' => 'Bad Phone', 'license_no' => 'ATL-DL-9002', 'phone' => 'call me'])->assertUnprocessable();
        $this->fleet('POST', '/drivers', '', $manager, ['name' => 'Wrong Company', 'license_no' => 'ATL-DL-9003', 'company_id' => $this->cedar()->id])->assertUnprocessable();

        // The same license in another company is a different driver.
        $this->fleet('POST', '/drivers', '', $this->apiToken($this->admin()), ['name' => 'Rami Aoun', 'license_no' => 'ATL-DL-1001', 'company_id' => $this->cedar()->id])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->cedar()->id);
        $this->assertSame(2, Driver::query()->where('license_no', 'ATL-DL-1001')->count());
    }

    public function test_an_inactive_companys_fleet_is_read_only(): void
    {
        $this->atlas()->forceFill(['status' => CompanyStatus::Inactive])->save();
        $manager = $this->apiToken($this->atlasManager());

        $this->fleet('POST', '/vehicles', '', $manager, ['plate_no' => 'ATL-960', 'fuel_type' => 'diesel', 'tank_capacity_l' => '60.00'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'company_inactive');
        $this->fleet('POST', '/drivers', '', $manager, ['name' => 'New Driver', 'license_no' => 'ATL-DL-9100'])->assertForbidden();
        $this->fleet('GET', '/vehicles', '', $manager)->assertOk()->assertJsonPath('meta.total', 5);
    }

    /**
     * A request to /api/v1{$path}{$query}, checked against openapi.json.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function fleet(string $method, string $path, string $query, ?string $token, array $body = []): TestResponse
    {
        return $this->assertMatchesOpenApi($this->api($method, '/api/v1'.$path.$query, $token, $body), $method, $path);
    }
}
