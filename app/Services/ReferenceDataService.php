<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Distributor master data maintained by admins: companies, stations and
 * products. Nothing is deleted; records are deactivated, and every status
 * change is audited. Product prices are published in M04.
 */
class ReferenceDataService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param array{name: string, tax_no?: string|null} $data */
    public function createCompany(array $data, User $actor): Company
    {
        $company = new Company(['name' => $data['name'], 'tax_no' => $data['tax_no'] ?? null]);
        $company->forceFill(['status' => CompanyStatus::Active])->save();

        return $company;
    }

    /** @param array{name: string, tax_no?: string|null} $data */
    public function updateCompany(Company $company, array $data, User $actor): Company
    {
        $company->fill(['name' => $data['name'], 'tax_no' => $data['tax_no'] ?? null])->save();

        return $company;
    }

    /**
     * An inactive company keeps its data and history; its fleet becomes
     * read-only and (from M05) its cards are declined at the pump.
     */
    public function setCompanyStatus(Company $company, CompanyStatus $status, User $actor): Company
    {
        return DB::transaction(function () use ($company, $status, $actor): Company {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);

            if ($company->status === $status) {
                return $company;
            }

            $old = $company->status;
            $company->forceFill(['status' => $status])->save();
            $this->audit->record($status === CompanyStatus::Active ? 'company.activated' : 'company.deactivated',
                $company, $actor, $company->id,
                ['status' => $old->value],
                ['status' => $status->value],
            );

            return $company;
        });
    }

    /** @param array{name: string, district: string, governorate: string, latitude?: string|null, longitude?: string|null} $data */
    public function createStation(array $data, User $actor): Station
    {
        $station = new Station($this->stationFields($data));
        $station->forceFill(['is_active' => true])->save();

        return $station;
    }

    /** @param array{name: string, district: string, governorate: string, latitude?: string|null, longitude?: string|null} $data */
    public function updateStation(Station $station, array $data, User $actor): Station
    {
        $station->fill($this->stationFields($data))->save();

        return $station;
    }

    /** An inactive station keeps its history; from M05 its POS purchases are declined. */
    public function setStationActive(Station $station, bool $active, User $actor): Station
    {
        return DB::transaction(function () use ($station, $active, $actor): Station {
            $station = Station::query()->lockForUpdate()->findOrFail($station->id);

            if ($station->is_active === $active) {
                return $station;
            }

            $station->forceFill(['is_active' => $active])->save();
            $this->audit->record($active ? 'station.activated' : 'station.deactivated', $station, $actor, null,
                ['is_active' => ! $active],
                ['is_active' => $active],
            );

            return $station;
        });
    }

    /** Only the display name changes; the product code and fuel type are fixed. */
    public function updateProduct(Product $product, string $name, User $actor): Product
    {
        $product->fill(['name' => $name])->save();

        return $product;
    }

    public function setProductActive(Product $product, bool $active, User $actor): Product
    {
        return DB::transaction(function () use ($product, $active, $actor): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($product->is_active === $active) {
                return $product;
            }

            $product->forceFill(['is_active' => $active])->save();
            $this->audit->record($active ? 'product.activated' : 'product.deactivated', $product, $actor, null,
                ['is_active' => ! $active],
                ['is_active' => $active],
            );

            return $product;
        });
    }

    /**
     * @param  array{name: string, district: string, governorate: string, latitude?: string|null, longitude?: string|null}  $data
     * @return array<string, string|null>
     */
    private function stationFields(array $data): array
    {
        return [
            'name' => $data['name'],
            'district' => $data['district'],
            'governorate' => $data['governorate'],
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ];
    }
}
