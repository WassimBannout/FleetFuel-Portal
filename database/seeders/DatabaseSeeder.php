<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * `make setup` runs this on every invocation, so every step only inserts
     * what is missing. Model events stay enabled so the append-only guards on
     * ledger, history and audit models also protect seeded data.
     */
    public function run(): void
    {
        $this->call(ProductSeeder::class);

        if (! config('fleetfuel.demo.enabled')) {
            $this->command->info('DEMO_MODE is off; demo data was not seeded.');

            return;
        }

        // Guarded: local/testing only, needs DEMO_PASSWORD, skips when users exist.
        $this->call(DemoSeeder::class);
    }
}
