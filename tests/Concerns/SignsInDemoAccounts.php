<?php

namespace Tests\Concerns;

use App\Enums\ProductCode;
use App\Models\Company;
use App\Models\Driver;
use App\Models\FuelCard;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;

/**
 * Lookups of records created by the demo seed (see DemoSeeder), for tests
 * that use seedDemo(): two companies (Atlas, Cedar), two operated stations.
 */
trait SignsInDemoAccounts
{
    protected function account(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    protected function admin(): User
    {
        return $this->account('admin@fleetfuel.test');
    }

    protected function atlasManager(): User
    {
        return $this->account('manager.atlas@fleetfuel.test');
    }

    protected function cedarManager(): User
    {
        return $this->account('manager.cedar@fleetfuel.test');
    }

    protected function operator(): User
    {
        return $this->account('operator.beirut@fleetfuel.test');
    }

    protected function company(string $name): Company
    {
        return Company::query()->where('name', $name)->firstOrFail();
    }

    protected function atlas(): Company
    {
        return $this->company('Atlas Logistics');
    }

    protected function cedar(): Company
    {
        return $this->company('Cedar Catering');
    }

    protected function card(string $number): FuelCard
    {
        return FuelCard::query()->where('card_no', $number)->firstOrFail();
    }

    protected function vehicle(string $plate): Vehicle
    {
        return Vehicle::query()->where('plate_no', $plate)->firstOrFail();
    }

    protected function driver(string $license): Driver
    {
        return Driver::query()->where('license_no', $license)->firstOrFail();
    }

    protected function product(ProductCode $code): Product
    {
        return Product::query()->where('code', $code->value)->firstOrFail();
    }

    protected function station(string $name): Station
    {
        return Station::query()->where('name', $name)->firstOrFail();
    }
}
