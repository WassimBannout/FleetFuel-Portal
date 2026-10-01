<?php

namespace App\Services;

use App\Models\FuelTransaction;
use App\Models\User;
use App\Support\CsvCell;
use App\Support\TransactionFilters;
use Generator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The accounting CSV (docs/05-API-CONTRACT.md, "CSV contract"): every
 * ledger row the caller may see that matches the filters, with no page
 * limit, in a fixed column order, streamed in chunks so memory stays flat.
 *
 * - Scope and filters are the transaction list's own (visibleTo() and
 *   TransactionFilters), so the file and the list totals cover the same
 *   rows.
 * - Chunks follow the (transacted_at, id) order with a keyset cursor
 *   ("after the last row read"), not OFFSET, so each chunk is an index
 *   range read. All chunks run in one transaction: InnoDB's repeatable
 *   read gives them one consistent snapshot, so a purchase arriving
 *   mid-export cannot be skipped or half-included.
 * - Text cells are neutralized against spreadsheet formulas (CsvCell).
 *   Amounts are the stored snapshots, exactly as recorded.
 */
class TransactionCsvExport
{
    public const COLUMNS = [
        'transaction_id', 'external_ref', 'company', 'vehicle_plate', 'station', 'product_code',
        'liters', 'unit_price_lbp', 'amount_lbp', 'amount_usd', 'rate_source', 'transacted_at_utc',
    ];

    public function download(User $user, TransactionFilters $filters, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($user, $filters): void {
            $out = fopen('php://output', 'w');
            assert($out !== false);

            // RFC 4180 quoting: an empty escape character means quotes are
            // doubled, never backslash-escaped.
            fputcsv($out, self::COLUMNS, ',', '"', '');

            DB::transaction(function () use ($out, $user, $filters): void {
                foreach ($this->rows($user, $filters) as $row) {
                    fputcsv($out, $row, ',', '"', '');
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The CSV lines, oldest first.
     *
     * @return Generator<int, list<string>>
     */
    public function rows(User $user, TransactionFilters $filters): Generator
    {
        $chunk = max(1, (int) config('fleetfuel.exports.chunk_size'));
        $cursor = null;

        do {
            $query = $filters->apply(FuelTransaction::query()->visibleTo($user))
                ->join('companies as c', 'c.id', '=', 'fuel_transactions.company_id')
                ->leftJoin('vehicles as v', 'v.id', '=', 'fuel_transactions.vehicle_id')
                ->join('stations as s', 's.id', '=', 'fuel_transactions.station_id')
                ->join('products as p', 'p.id', '=', 'fuel_transactions.product_id')
                ->select([
                    'fuel_transactions.id', 'fuel_transactions.external_ref', 'c.name as company', 'v.plate_no as vehicle_plate',
                    's.name as station', 'p.code as product_code', 'fuel_transactions.liters', 'fuel_transactions.unit_price_lbp',
                    'fuel_transactions.amount_lbp', 'fuel_transactions.amount_usd', 'fuel_transactions.rate_source',
                    'fuel_transactions.transacted_at',
                ])
                ->orderBy('fuel_transactions.transacted_at')
                ->orderBy('fuel_transactions.id')
                ->limit($chunk);

            if ($cursor !== null) {
                [$at, $id] = $cursor;
                $query->where(fn ($after) => $after
                    ->where('fuel_transactions.transacted_at', '>', $at)
                    ->orWhere(fn ($same) => $same->where('fuel_transactions.transacted_at', $at)->where('fuel_transactions.id', '>', $id)));
            }

            $rows = $query->toBase()->get();

            foreach ($rows as $row) {
                yield [
                    (string) $row->id,
                    CsvCell::text($row->external_ref),
                    CsvCell::text($row->company),
                    CsvCell::text($row->vehicle_plate),
                    CsvCell::text($row->station),
                    CsvCell::text($row->product_code),
                    (string) $row->liters,
                    (string) $row->unit_price_lbp,
                    (string) $row->amount_lbp,
                    (string) $row->amount_usd,
                    CsvCell::text($row->rate_source),
                    str_replace(' ', 'T', (string) $row->transacted_at).'Z',
                ];
            }

            $last = $rows->last();
            $cursor = $last === null ? null : [$last->transacted_at, $last->id];
        } while ($rows->count() === $chunk);
    }
}
