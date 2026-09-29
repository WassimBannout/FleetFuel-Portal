<?php

namespace App\Console\Commands;

use App\Services\UsageReconciliation;
use Illuminate\Console\Command;

class ReconcileUsage extends Command
{
    protected $signature = 'usage:reconcile';

    protected $description = 'Compare every monthly usage counter with the fuel ledger it summarizes (read-only)';

    public function handle(UsageReconciliation $reconciliation): int
    {
        $mismatches = $reconciliation->mismatches();

        if ($mismatches === []) {
            $this->info('All monthly usage counters match the ledger.');

            return self::SUCCESS;
        }

        // Card IDs, never card numbers: output may end up in logs.
        $this->error(count($mismatches).' counter(s) differ from the ledger. Nothing was changed; investigate before any correction.');
        $this->table(
            ['Card ID', 'Month', 'Counter L', 'Ledger L', 'Counter USD', 'Ledger USD'],
            array_map(fn (array $row): array => [
                $row['fuel_card_id'], $row['month'], $row['counter_l'], $row['ledger_l'], $row['counter_usd'], $row['ledger_usd'],
            ], $mismatches),
        );

        return self::FAILURE;
    }
}
