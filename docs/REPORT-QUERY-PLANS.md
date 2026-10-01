# Report query plans (M08)

Real MySQL 8.4.11 plans for the report and CSV queries, measured on representative data. They justify the one index M08 adds and the shape of the efficiency query. Timings are from single `EXPLAIN ANALYZE` runs on a laptop running on battery. Treat them as orders of magnitude, not benchmarks; the row counts are exact.

## How it was measured

`php artisan reports:explain` captures the exact SQL that `ReportRepository` and `TransactionCsvExport` build (with `DB::pretend()`) and prints MySQL's plan for each query. It is read-only; `--analyze` executes the SELECTs to report real row counts and times.

```bash
docker compose exec app php artisan reports:explain                          # the current month, all companies
docker compose exec app php artisan reports:explain --analyze --company=1 \
    --from=2026-09-01 --to=2026-10-01                                        # one company, as its manager sees it
```

The demo seed has only 32 purchases, so every plan on it is a full table scan and says nothing. The measurements below used a throwaway database in the test MySQL server, `fleetfuel_test_explain`, which was dropped afterwards:

1. Migrate, then `demo:seed --as-of=2026-09-28T09:00:00Z`.
2. Insert each seeded purchase 2,000 more times, the k-th copy shifted k × 7 hours back. This gives 64,032 purchases from December 2024 to September 2026, with the seed's 2 companies, 8 vehicles and 3 stations.
3. `ANALYZE TABLE`.

The report range is September 2026 in Beirut, `[2026-08-31 21:00, 2026-09-30 21:00)` UTC:
- 735 purchases for all companies (1.1% of the table);
- 407 for company 1.

Limits of this data:
- Two companies is fewer than a real distributor has.
- The copies repeat the seed's odometer readings, so many efficiency rows are "not higher".
- Cards (10), monthly counters and delivery history (16 rows) stay at seed size.

## Results

Time to produce the result, in ms. "Before" is the first version of M08; "after" adds the `(transacted_at, id)` index and the bounded efficiency query.

| Query | All companies, before | All companies, after | One company, before | One company, after |
| --- | --- | --- | --- | --- |
| Consumption by company | 110 | 15.2 | 4.07 | 2.56 |
| Consumption by vehicle | 87.4 | 21.2 | 11.1 | 6.44 |
| Consumption by product | 83.8 | 18.3 | 6.88 | 4.59 |
| Top stations | 84.4 | 19.6 | 7.52 | 4.97 |
| Quota exceptions | 0.27 | 0.90 | 0.42 | 0.35 |
| Tank overfills | 86.8 | 10.9 | 7.98 | 5.76 |
| Rapid fills | 93.2 | 40.9 | 26.6 | 21.2 |
| Efficiency estimate | 747 | 58.7 | 667 | 28.3 |
| Delivery SLA | 0.19 | 0.46 | 0.19 | 0.55 |
| CSV export, one 500-row chunk | 104 | 18.1 | 6.11 | 14.2 |

Sub-millisecond differences (quota exceptions, delivery SLA) are noise between runs: those plans did not change.

## What the plans show

**One company (a manager's view) was already fine.**
- Every ledger query reads `(company_id, transacted_at, id)` as an index range: `Index range scan on t using fuel_transactions_company_id_transacted_at_id_index over (company_id = 1 AND '2026-08-31 21:00:00' <= transacted_at < '2026-09-30 21:00:00')`, 407 rows.
- The grouping then happens in a small temporary table.

**All companies (the admin's default view) read the whole table.**
- No index started with `transacted_at`, so the date range was checked row by row: `Table scan on t ... rows=64032`, keeping 735.
- The CSV export was worse. Every 500-row chunk repeated that scan and then sorted the matches, so an export of n rows cost about n / 500 full scans.

**Decision: add `fuel_transactions (transacted_at, id)`** (migration `2026_10_01_120000`). After it, the same queries read only the range: `Index range scan on t using fuel_transactions_transacted_at_id_index over ('2026-08-31 21:00:00' <= transacted_at < '2026-09-30 21:00:00')`, 735 rows. The keyset cursor of the CSV export (`transacted_at > ? OR (transacted_at = ? AND id > ?)`, ordered by both) follows the same index, so each chunk reads only its own rows.
- The cost: one more index entry per accepted purchase. The ledger is append-only and written about once per fill, so this is cheap compared with full scans on every admin report.
- The tenant indexes stay; a manager's queries still use `(company_id, transacted_at, id)`.

**Efficiency estimate: bound the lookback.**
- Each fill needs the same vehicle's previous fill, which may be months before the range. The first version ran `LAG()` over every earlier fill of every vehicle: `Materialize CTE fills ... rows=56028`, then a 56,000-row sort, 747 ms.
- The query now gives the window only the fills in range plus each vehicle's single last fill before the range: one `LIMIT 1` lookup per vehicle.

**A regression found while measuring.**
- After the time index existed, MySQL served that per-vehicle lookup by walking the new index backwards and checking each row's vehicle: `Index scan on p using fuel_transactions_transacted_at_id_index (reverse) ... rows=26057 loops=5`, about 570 ms.
- It preferred an index that already matched `ORDER BY transacted_at DESC`.
- Ordering by `vehicle_id DESC, transacted_at DESC, id DESC` returns the same row, because the vehicle is fixed in that lookup. It makes `(vehicle_id, transacted_at, id)` the only index that serves both the filter and the order: `Covering index lookup on p using fuel_transactions_vehicle_id_transacted_at_id_index (vehicle_id=v.id) (reverse)`. Result: 28–59 ms.
- A new index can change the plan of a query you did not touch, so re-measure the neighbors.

**Rapid fills** need only fills less than 30 minutes before the range start, so the window reads `[from − 30 min, to)`: `Index range scan ... over (company_id = 1 AND '2026-08-31 20:30:00' <= transacted_at < ...)`. An older predecessor cannot be within 30 minutes.

**Quota exceptions and delivery SLA** read small tables:
- the 10 cards, with one unique-key lookup per card into the monthly counters;
- the history, through `(delivery_order_id, changed_at, id)`.

Not added:
- `(to_status, changed_at)` on `delivery_status_history`. The history grows by about four rows per order; revisit if the SLA plan starts scanning thousands of rows.
- No product/time index. Product grouping aggregates the range it has already read, and the CSV's `product_code` filter is a semi-join on the 3-row products table.
