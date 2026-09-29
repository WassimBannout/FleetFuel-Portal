<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Compares every monthly usage counter with the ledger it summarizes.
 *
 * Read-only: it reports differences and never repairs them. M05 wraps it in
 * an Artisan command that exits nonzero when anything differs.
 */
final class UsageReconciliation
{
    /**
     * @return list<array{fuel_card_id: int, month: string, counter_l: string, ledger_l: string, counter_usd: string, ledger_usd: string}>
     */
    public function mismatches(): array
    {
        $ledger = DB::table('fuel_transactions')
            ->selectRaw('fuel_card_id, quota_month, SUM(liters) AS liters, SUM(amount_usd) AS usd')
            ->groupBy('fuel_card_id', 'quota_month')
            ->get()
            ->keyBy(fn (object $row): string => $row->fuel_card_id.'|'.$row->quota_month);

        $counters = DB::table('card_monthly_usage')
            ->get(['fuel_card_id', 'month_start', 'used_l', 'used_usd'])
            ->keyBy(fn (object $row): string => $row->fuel_card_id.'|'.$row->month_start);

        $keys = $ledger->keys()->merge($counters->keys())->unique()->sort()->values();

        $mismatches = [];

        foreach ($keys as $key) {
            [$cardId, $month] = explode('|', (string) $key);

            $counterL = (string) ($counters->get($key)->used_l ?? '0.00');
            $counterUsd = (string) ($counters->get($key)->used_usd ?? '0.00');
            $ledgerL = (string) ($ledger->get($key)->liters ?? '0.00');
            $ledgerUsd = (string) ($ledger->get($key)->usd ?? '0.00');

            if (BigDecimal::of($counterL)->isEqualTo($ledgerL) && BigDecimal::of($counterUsd)->isEqualTo($ledgerUsd)) {
                continue;
            }

            $mismatches[] = [
                'fuel_card_id' => (int) $cardId,
                'month' => $month,
                'counter_l' => $counterL,
                'ledger_l' => $ledgerL,
                'counter_usd' => $counterUsd,
                'ledger_usd' => $ledgerUsd,
            ];
        }

        return $mismatches;
    }
}
