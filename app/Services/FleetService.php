<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Vehicles and drivers, for the web screens now and the API in M06. The
 * company is chosen by the server when a record is created and never
 * changes. An inactive company's fleet is read-only, except that vehicles
 * and drivers can still be deactivated.
 */
class FleetService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{plate_no: string, fuel_type: string, tank_capacity_l: string, odometer_km?: int|string|null}  $data
     */
    public function createVehicle(Company $company, array $data, User $actor): Vehicle
    {
        $this->ensureCompanyActive($company);

        $vehicle = new Vehicle([
            'plate_no' => Vehicle::normalizePlate($data['plate_no']),
            'fuel_type' => $data['fuel_type'],
            'tank_capacity_l' => Decimal::normalize($data['tank_capacity_l']),
            'odometer_km' => $data['odometer_km'] ?? null,
        ]);
        $vehicle->forceFill(['company_id' => $company->id, 'is_active' => true])->save();

        return $vehicle;
    }

    /**
     * Plate, tank capacity and odometer. Fuel type and company are fixed at
     * creation; purchases keep their own tank-capacity snapshot.
     *
     * @param  array{plate_no: string, tank_capacity_l: string, odometer_km?: int|string|null}  $data
     */
    public function updateVehicle(Vehicle $vehicle, array $data, User $actor): Vehicle
    {
        $this->ensureCompanyActive($this->companyOf($vehicle->company_id));

        $vehicle->fill([
            'plate_no' => Vehicle::normalizePlate($data['plate_no']),
            'tank_capacity_l' => Decimal::normalize($data['tank_capacity_l']),
            'odometer_km' => $data['odometer_km'] ?? null,
        ])->save();

        return $vehicle;
    }

    public function setVehicleActive(Vehicle $vehicle, bool $active, User $actor): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $active, $actor): Vehicle {
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);

            if ($vehicle->is_active === $active) {
                return $vehicle;
            }

            if ($active) {
                $this->ensureCompanyActive($this->companyOf($vehicle->company_id));
            }

            $vehicle->forceFill(['is_active' => $active])->save();
            $this->audit->record($active ? 'vehicle.activated' : 'vehicle.deactivated', $vehicle, $actor, $vehicle->company_id,
                ['is_active' => ! $active],
                ['is_active' => $active],
            );

            return $vehicle;
        });
    }

    /**
     * @param  array{name: string, license_no: string, phone?: string|null}  $data
     */
    public function createDriver(Company $company, array $data, User $actor): Driver
    {
        $this->ensureCompanyActive($company);

        $driver = new Driver([
            'name' => $data['name'],
            'license_no' => Driver::normalizeLicense($data['license_no']),
            'phone' => $data['phone'] ?? null,
        ]);
        $driver->forceFill(['company_id' => $company->id, 'is_active' => true])->save();

        return $driver;
    }

    /**
     * @param  array{name: string, license_no: string, phone?: string|null}  $data
     */
    public function updateDriver(Driver $driver, array $data, User $actor): Driver
    {
        $this->ensureCompanyActive($this->companyOf($driver->company_id));

        $driver->fill([
            'name' => $data['name'],
            'license_no' => Driver::normalizeLicense($data['license_no']),
            'phone' => $data['phone'] ?? null,
        ])->save();

        return $driver;
    }

    public function setDriverActive(Driver $driver, bool $active, User $actor): Driver
    {
        return DB::transaction(function () use ($driver, $active, $actor): Driver {
            $driver = Driver::query()->lockForUpdate()->findOrFail($driver->id);

            if ($driver->is_active === $active) {
                return $driver;
            }

            if ($active) {
                $this->ensureCompanyActive($this->companyOf($driver->company_id));
            }

            $driver->forceFill(['is_active' => $active])->save();
            $this->audit->record($active ? 'driver.activated' : 'driver.deactivated', $driver, $actor, $driver->company_id,
                ['is_active' => ! $active],
                ['is_active' => $active],
            );

            return $driver;
        });
    }

    private function companyOf(int $companyId): Company
    {
        return Company::query()->findOrFail($companyId);
    }

    private function ensureCompanyActive(Company $company): void
    {
        if ($company->status !== CompanyStatus::Active) {
            throw BusinessRuleViolation::companyInactive();
        }
    }
}
