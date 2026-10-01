<?php

namespace App\Repositories;

use App\Support\Decimal;
use App\Support\ReportScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The reporting SQL (docs/04-BUSINESS-RULES.md, "Reports and anomalies").
 *
 * - Every value is a bound parameter. Identifiers (grouping columns,
 *   labels, joins) come from fixed allowlists in this class, never from
 *   the request.
 * - Ledger reports read the immutable snapshots stored on each accepted
 *   purchase: company, vehicle, tank capacity, odometer, LBP and USD
 *   amounts. Nothing is repriced with today's prices or rates, and card
 *   assignments, which can differ from what applied at purchase time,
 *   are never joined to decide ownership.
 * - Date ranges are half-open UTC ranges [from, to); ties are broken by id
 *   so the same data always gives the same order.
 * - Decimals stay strings; derived figures use brick/math with explicit
 *   half-up rounding.
 *
 * Only MySQL is implemented and tested (timestamp differences use
 * TIMESTAMPDIFF); SQL Server is the optional S01 milestone.
 */
class ReportRepository
{
    /** A fill this soon after the vehicle's previous fill is flagged. */
    public const RAPID_FILL_SECONDS = 1800;

    /** [group id column, label expression, join] for each allowed grouping. */
    private const CONSUMPTION_GROUPS = [
        'company' => ['t.company_id', 'c.name', 'JOIN companies c ON c.id = t.company_id'],
        'vehicle' => ['t.vehicle_id', "COALESCE(v.plate_no, 'No vehicle (card only)')", 'LEFT JOIN vehicles v ON v.id = t.vehicle_id'],
        'product' => ['t.product_id', "CONCAT(p.name, ' (', p.code, ')')", 'JOIN products p ON p.id = t.product_id'],
    ];

    /**
     * Liters and amounts per company, vehicle or product, grouped by ID so
     * two records with the same name never merge. Card-only purchases form
     * one group with a null ID when grouping by vehicle. Largest first,
     * then by ID (null last).
     *
     * @return list<array{group_id: ?int, label: string, purchases: int, liters: string, amount_lbp: string, amount_usd: string}>
     */
    public function consumption(ReportScope $scope, string $groupBy): array
    {
        [$id, $label, $join] = self::CONSUMPTION_GROUPS[$groupBy]
            ?? throw new InvalidArgumentException("Unknown consumption grouping \"{$groupBy}\".");
        [$where, $bindings] = $this->ledgerWhere($scope);

        $rows = DB::select(<<<SQL
            SELECT {$id} AS group_id, {$label} AS label, COUNT(*) AS purchases,
                   SUM(t.liters) AS liters, SUM(t.amount_lbp) AS amount_lbp, SUM(t.amount_usd) AS amount_usd
            FROM fuel_transactions t
            {$join}
            WHERE {$where}
            GROUP BY {$id}, {$label}
            ORDER BY SUM(t.liters) DESC, CASE WHEN {$id} IS NULL THEN 1 ELSE 0 END, {$id}
            SQL, $bindings);

        return array_map(fn (object $row): array => [
            'group_id' => $row->group_id === null ? null : (int) $row->group_id,
            'label' => (string) $row->label,
            'purchases' => (int) $row->purchases,
            'liters' => $this->decimal($row->liters),
            'amount_lbp' => $this->decimal($row->amount_lbp),
            'amount_usd' => $this->decimal($row->amount_usd),
        ], $rows);
    }

    /**
     * The ten stations with the most liters in range, ties broken by
     * station ID.
     *
     * @return list<array{station_id: int, station: string, governorate: string, purchases: int, liters: string, amount_lbp: string, amount_usd: string}>
     */
    public function topStations(ReportScope $scope, int $limit = 10): array
    {
        [$where, $bindings] = $this->ledgerWhere($scope);

        $rows = DB::select(<<<SQL
            SELECT t.station_id, s.name AS station, s.governorate, COUNT(*) AS purchases,
                   SUM(t.liters) AS liters, SUM(t.amount_lbp) AS amount_lbp, SUM(t.amount_usd) AS amount_usd
            FROM fuel_transactions t
            JOIN stations s ON s.id = t.station_id
            WHERE {$where}
            GROUP BY t.station_id, s.name, s.governorate
            ORDER BY SUM(t.liters) DESC, t.station_id
            LIMIT ?
            SQL, [...$bindings, $limit]);

        return array_map(fn (object $row): array => [
            'station_id' => (int) $row->station_id,
            'station' => (string) $row->station,
            'governorate' => (string) $row->governorate,
            'purchases' => (int) $row->purchases,
            'liters' => $this->decimal($row->liters),
            'amount_lbp' => $this->decimal($row->amount_lbp),
            'amount_usd' => $this->decimal($row->amount_usd),
        ], $rows);
    }

    /**
     * Cards needing attention this Beirut month, judged by the monthly
     * counter against today's limits: blocked cards, and cards at or over
     * a limit (lowering a limit below usage puts a card over it). Archived
     * cards are left out. The date filter does not apply: counters are
     * monthly.
     *
     * @param  string  $monthStart  Y-m-01, the Beirut month
     * @return list<array{card_id: int, card_no: string, company_id: int, company: string, vehicle_plate: ?string, status: string, monthly_limit_l: ?string, monthly_limit_usd: ?string, used_l: string, used_usd: string, reasons: list<string>}>
     */
    public function quotaExceptions(?int $companyId, string $monthStart): array
    {
        $bindings = [$monthStart];
        $companyCondition = '';

        if ($companyId !== null) {
            $companyCondition = 'AND f.company_id = ?';
            $bindings[] = $companyId;
        }

        $rows = DB::select(<<<SQL
            SELECT f.id AS card_id, f.card_no, f.company_id, c.name AS company, v.plate_no AS vehicle_plate, f.status,
                   f.monthly_limit_l, f.monthly_limit_usd,
                   COALESCE(u.used_l, 0) AS used_l, COALESCE(u.used_usd, 0) AS used_usd
            FROM fuel_cards f
            JOIN companies c ON c.id = f.company_id
            LEFT JOIN vehicles v ON v.id = f.vehicle_id
            LEFT JOIN card_monthly_usage u ON u.fuel_card_id = f.id AND u.month_start = ?
            WHERE f.status IN ('active', 'blocked') {$companyCondition}
              AND (
                  f.status = 'blocked'
                  OR (f.monthly_limit_l IS NOT NULL AND COALESCE(u.used_l, 0) >= f.monthly_limit_l)
                  OR (f.monthly_limit_usd IS NOT NULL AND COALESCE(u.used_usd, 0) >= f.monthly_limit_usd)
              )
            ORDER BY f.company_id, f.card_no
            SQL, $bindings);

        return array_map(function (object $row): array {
            $card = [
                'card_id' => (int) $row->card_id,
                'card_no' => (string) $row->card_no,
                'company_id' => (int) $row->company_id,
                'company' => (string) $row->company,
                'vehicle_plate' => $row->vehicle_plate === null ? null : (string) $row->vehicle_plate,
                'status' => (string) $row->status,
                'monthly_limit_l' => $row->monthly_limit_l === null ? null : $this->decimal($row->monthly_limit_l),
                'monthly_limit_usd' => $row->monthly_limit_usd === null ? null : $this->decimal($row->monthly_limit_usd),
                'used_l' => $this->decimal($row->used_l),
                'used_usd' => $this->decimal($row->used_usd),
            ];

            return $card + ['reasons' => $this->quotaReasons($card)];
        }, $rows);
    }

    /**
     * Why a card is listed. Usage can only pass a limit when the limit was
     * lowered below it, because POS ingestion never accepts an excess.
     *
     * @param  array{status: string, monthly_limit_l: ?string, monthly_limit_usd: ?string, used_l: string, used_usd: string}  $card
     * @return list<string>
     */
    private function quotaReasons(array $card): array
    {
        $reasons = $card['status'] === 'blocked' ? ['Blocked: every purchase is declined.'] : [];

        foreach ([['monthly_limit_l', 'used_l', 'liter', 'L'], ['monthly_limit_usd', 'used_usd', 'USD', 'USD']] as [$limitKey, $usedKey, $name, $unit]) {
            $limit = $card[$limitKey];

            if ($limit === null) {
                continue;
            }

            $comparison = BigDecimal::of($card[$usedKey])->compareTo($limit);

            if ($comparison > 0) {
                $reasons[] = "Over the {$name} limit: {$card[$usedKey]} {$unit} used of {$limit} {$unit} (the limit was lowered below this month's usage).";
            } elseif ($comparison === 0) {
                $reasons[] = ucfirst($name)." limit reached: {$card[$usedKey]} of {$limit} {$unit} used, nothing left this month.";
            }
        }

        return $reasons;
    }

    /**
     * Purchases of more liters than the vehicle's tank held at purchase
     * time (the stored snapshot). Purchases without a vehicle, or without
     * a capacity, are never compared.
     *
     * @return list<array{id: int, transacted_at: CarbonImmutable, company: string, vehicle_plate: ?string, station: string, liters: string, tank_capacity_l: string}>
     */
    public function tankOverfills(ReportScope $scope): array
    {
        [$where, $bindings] = $this->ledgerWhere($scope);

        $rows = DB::select(<<<SQL
            SELECT t.id, t.transacted_at, c.name AS company, v.plate_no AS vehicle_plate, s.name AS station,
                   t.liters, t.tank_capacity_l
            FROM fuel_transactions t
            JOIN companies c ON c.id = t.company_id
            LEFT JOIN vehicles v ON v.id = t.vehicle_id
            JOIN stations s ON s.id = t.station_id
            WHERE {$where}
              AND t.tank_capacity_l IS NOT NULL
              AND t.liters > t.tank_capacity_l
            ORDER BY t.transacted_at, t.id
            SQL, $bindings);

        return array_map(fn (object $row): array => [
            'id' => (int) $row->id,
            'transacted_at' => $this->instant($row->transacted_at),
            'company' => (string) $row->company,
            'vehicle_plate' => $row->vehicle_plate === null ? null : (string) $row->vehicle_plate,
            'station' => (string) $row->station,
            'liters' => $this->decimal($row->liters),
            'tank_capacity_l' => $this->decimal($row->tank_capacity_l),
        ], $rows);
    }

    /**
     * Fills less than 30 minutes after the same vehicle's previous fill.
     *
     * LAG() finds each fill's predecessor in (transacted_at, id) order, so
     * two distinct fills in the same second are ordered by ID and the later
     * one is flagged. The window also reads the 30 minutes before the range
     * start: the first fill in range is flagged when its predecessor came
     * just before. An older predecessor cannot be within 30 minutes, so it
     * does not need to be read. Card-only purchases have no vehicle and are
     * never flagged.
     *
     * @return list<array{id: int, transacted_at: CarbonImmutable, company: string, vehicle_id: int, vehicle_plate: string, station: string, liters: string, previous_id: int, previous_at: CarbonImmutable, seconds_since_previous: int}>
     */
    public function rapidFills(ReportScope $scope): array
    {
        $lookbackFrom = $scope->from->subSeconds(self::RAPID_FILL_SECONDS);
        [$companyCondition, $companyBindings] = $this->companyCondition($scope, 't');
        $gap = $this->secondsBetween('f.previous_at', 'f.transacted_at');

        $rows = DB::select(<<<SQL
            WITH fills AS (
                SELECT t.id, t.company_id, t.vehicle_id, t.station_id, t.transacted_at, t.liters,
                       LAG(t.id) OVER w AS previous_id,
                       LAG(t.transacted_at) OVER w AS previous_at
                FROM fuel_transactions t
                WHERE t.vehicle_id IS NOT NULL
                  AND t.transacted_at >= ? AND t.transacted_at < ? {$companyCondition}
                WINDOW w AS (PARTITION BY t.vehicle_id ORDER BY t.transacted_at, t.id)
            )
            SELECT f.id, f.transacted_at, c.name AS company, f.vehicle_id, v.plate_no AS vehicle_plate, s.name AS station,
                   f.liters, f.previous_id, f.previous_at, {$gap} AS seconds_since_previous
            FROM fills f
            JOIN companies c ON c.id = f.company_id
            JOIN vehicles v ON v.id = f.vehicle_id
            JOIN stations s ON s.id = f.station_id
            WHERE f.transacted_at >= ?
              AND f.previous_id IS NOT NULL
              AND {$gap} < ?
            ORDER BY f.transacted_at, f.id
            SQL, [$lookbackFrom, $scope->to, ...$companyBindings, $scope->from, self::RAPID_FILL_SECONDS]);

        return array_map(fn (object $row): array => [
            'id' => (int) $row->id,
            'transacted_at' => $this->instant($row->transacted_at),
            'company' => (string) $row->company,
            'vehicle_id' => (int) $row->vehicle_id,
            'vehicle_plate' => (string) $row->vehicle_plate,
            'station' => (string) $row->station,
            'liters' => $this->decimal($row->liters),
            'previous_id' => (int) $row->previous_id,
            'previous_at' => $this->instant($row->previous_at),
            'seconds_since_previous' => (int) $row->seconds_since_previous,
        ], $rows);
    }

    /**
     * An estimate of kilometers per liter for each vehicle fill in range:
     * (odometer - previous fill's odometer) / liters, assuming every fill
     * fills the tank (full to full). Without a previous fill, a reading on
     * either side, or an increasing reading, the estimate is null with the
     * reason; nothing is invented, and the vehicle's current odometer is
     * never used.
     *
     * The previous fill of the first fill in range may be months earlier.
     * Rather than run LAG() over each vehicle's whole history, the window
     * gets the fills in range plus each vehicle's last fill before the
     * range, found with one backwards seek per vehicle on the
     * (vehicle_id, transacted_at, id) index (docs/REPORT-QUERY-PLANS.md).
     *
     * @return list<array{id: int, transacted_at: CarbonImmutable, company: string, vehicle_plate: string, liters: string, odometer_km: ?int, previous_odometer_km: ?int, distance_km: ?int, km_per_liter: ?string, note: ?string}>
     */
    public function efficiency(ReportScope $scope): array
    {
        [$companyCondition, $companyBindings] = $this->companyCondition($scope, 't');
        [$vehicleCondition, $vehicleBindings] = $scope->companyId === null ? ['', []] : ['WHERE v.company_id = ?', [$scope->companyId]];

        $rows = DB::select(<<<SQL
            WITH candidates AS (
                SELECT t.id, t.company_id, t.vehicle_id, t.transacted_at, t.liters, t.odometer_km
                FROM fuel_transactions t
                WHERE t.vehicle_id IS NOT NULL
                  AND t.transacted_at >= ? AND t.transacted_at < ? {$companyCondition}
                UNION ALL
                SELECT t.id, t.company_id, t.vehicle_id, t.transacted_at, t.liters, t.odometer_km
                FROM fuel_transactions t
                JOIN (
                    SELECT (
                        SELECT p.id FROM fuel_transactions p
                        WHERE p.vehicle_id = v.id AND p.transacted_at < ?
                        -- vehicle_id is fixed here; naming it makes the
                        -- (vehicle_id, transacted_at, id) index the only one
                        -- that serves both filter and order, so MySQL seeks
                        -- there instead of walking the time index backwards.
                        ORDER BY p.vehicle_id DESC, p.transacted_at DESC, p.id DESC
                        LIMIT 1
                    ) AS id
                    FROM vehicles v {$vehicleCondition}
                ) last_before ON last_before.id = t.id
            ),
            fills AS (
                SELECT c.*,
                       LAG(c.id) OVER w AS previous_id,
                       LAG(c.odometer_km) OVER w AS previous_odometer_km
                FROM candidates c
                WINDOW w AS (PARTITION BY c.vehicle_id ORDER BY c.transacted_at, c.id)
            )
            SELECT f.id, f.transacted_at, co.name AS company, v.plate_no AS vehicle_plate,
                   f.liters, f.odometer_km, f.previous_id, f.previous_odometer_km
            FROM fills f
            JOIN companies co ON co.id = f.company_id
            JOIN vehicles v ON v.id = f.vehicle_id
            WHERE f.transacted_at >= ?
            ORDER BY v.plate_no, f.vehicle_id, f.transacted_at, f.id
            SQL, [$scope->from, $scope->to, ...$companyBindings, $scope->from, ...$vehicleBindings, $scope->from]);

        return array_map(function (object $row): array {
            $odometer = $row->odometer_km === null ? null : (int) $row->odometer_km;
            $previous = $row->previous_odometer_km === null ? null : (int) $row->previous_odometer_km;

            $note = match (true) {
                $row->previous_id === null => 'First recorded fill: nothing to compare with.',
                $odometer === null || $previous === null => 'Odometer reading missing.',
                $odometer <= $previous => 'Odometer not higher than at the previous fill.',
                default => null,
            };

            $distance = $note === null ? $odometer - $previous : null;

            return [
                'id' => (int) $row->id,
                'transacted_at' => $this->instant($row->transacted_at),
                'company' => (string) $row->company,
                'vehicle_plate' => (string) $row->vehicle_plate,
                'liters' => $this->decimal($row->liters),
                'odometer_km' => $odometer,
                'previous_odometer_km' => $previous,
                'distance_km' => $distance,
                'km_per_liter' => $distance === null ? null
                    : (string) BigDecimal::of($distance)->dividedBy((string) $row->liters, 2, RoundingMode::HalfUp),
                'note' => $note,
            ];
        }, $rows);
    }

    /**
     * Hours from the request (the order's first, null -> pending history
     * row) to delivery (its delivered history row), per governorate, for
     * orders delivered in range. Pending, in-progress and cancelled orders
     * have no delivered row, so they are left out.
     *
     * @return list<array{governorate: string, delivered: int, average_hours: string, fastest_hours: string, slowest_hours: string, total_seconds: int}>
     */
    public function deliverySla(?int $companyId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $seconds = $this->secondsBetween('requested.changed_at', 'delivered.changed_at');
        $bindings = [$from, $to];
        $companyCondition = '';

        if ($companyId !== null) {
            $companyCondition = 'AND o.company_id = ?';
            $bindings[] = $companyId;
        }

        $rows = DB::select(<<<SQL
            SELECT o.governorate, COUNT(*) AS delivered,
                   SUM({$seconds}) AS total_seconds, MIN({$seconds}) AS fastest_seconds, MAX({$seconds}) AS slowest_seconds
            FROM delivery_orders o
            JOIN delivery_status_history requested
                ON requested.delivery_order_id = o.id AND requested.from_status IS NULL AND requested.to_status = 'pending'
            JOIN delivery_status_history delivered
                ON delivered.delivery_order_id = o.id AND delivered.to_status = 'delivered'
            WHERE delivered.changed_at >= ? AND delivered.changed_at < ? {$companyCondition}
            GROUP BY o.governorate
            ORDER BY o.governorate
            SQL, $bindings);

        return array_map(fn (object $row): array => [
            'governorate' => (string) $row->governorate,
            'delivered' => (int) $row->delivered,
            'average_hours' => self::hours((string) $row->total_seconds, (int) $row->delivered),
            'fastest_hours' => self::hours((string) $row->fastest_seconds),
            'slowest_hours' => self::hours((string) $row->slowest_seconds),
            'total_seconds' => (int) $row->total_seconds,
        ], $rows);
    }

    /** Seconds (divided by a count) as hours with two decimals, rounded half up. */
    public static function hours(string $seconds, int $count = 1): string
    {
        return (string) BigDecimal::of($seconds)->dividedBy(3600 * $count, 2, RoundingMode::HalfUp);
    }

    /**
     * The ledger range and company condition, with bindings.
     *
     * @return array{string, list<mixed>}
     */
    private function ledgerWhere(ReportScope $scope): array
    {
        [$companyCondition, $companyBindings] = $this->companyCondition($scope, 't');

        return [
            "t.transacted_at >= ? AND t.transacted_at < ? {$companyCondition}",
            [$scope->from, $scope->to, ...$companyBindings],
        ];
    }

    /**
     * @return array{string, list<int>}
     */
    private function companyCondition(ReportScope $scope, string $alias): array
    {
        return $scope->companyId === null ? ['', []] : ["AND {$alias}.company_id = ?", [$scope->companyId]];
    }

    /** Whole seconds from $earlier to $later, as SQL for the connection's driver. */
    private function secondsBetween(string $earlier, string $later): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "TIMESTAMPDIFF(SECOND, {$earlier}, {$later})",
            default => throw new LogicException('Report SQL is implemented for MySQL only; SQL Server is the optional S01 milestone.'),
        };
    }

    private function decimal(mixed $value): string
    {
        return (string) Decimal::normalize((string) ($value ?? '0'));
    }

    private function instant(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $value, 'UTC');
    }
}
