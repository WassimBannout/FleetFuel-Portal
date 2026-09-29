<?php

namespace App\Console\Commands;

use App\Services\ExchangeRateSync;
use Illuminate\Console\Command;

class SyncExchangeRates extends Command
{
    protected $signature = 'rates:sync {--force : Fetch even if the provider\'s next update is not due yet}';

    protected $description = 'Fetch and store the latest USD/LBP observation (live provider, or a synthetic fixture in fixture mode)';

    public function handle(ExchangeRateSync $sync): int
    {
        $result = $sync->run((bool) $this->option('force'));

        if ($result->failed()) {
            $this->error($result->message);

            return self::FAILURE;
        }

        $this->info($result->message);

        return self::SUCCESS;
    }
}
