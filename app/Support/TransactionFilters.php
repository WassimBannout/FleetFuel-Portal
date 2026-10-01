<?php

namespace App\Support;

use App\Models\FuelTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ledger filters shared by the transaction list, its totals and the
 * accounting CSV (docs/05-API-CONTRACT.md): a half-open UTC range
 * [from, to) plus optional card, station, company and product. One object
 * applies them everywhere, so a list's totals and the CSV of the same
 * filter always cover the same rows. Values are bound; column names are
 * fixed here.
 */
final readonly class TransactionFilters
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?string $cardNo = null,
        public ?int $stationId = null,
        public ?int $companyId = null,
        public ?string $productCode = null,
    ) {}

    /**
     * Narrow a ledger query that is already tenant-scoped with visibleTo().
     *
     * @param  Builder<FuelTransaction>  $query
     * @return Builder<FuelTransaction>
     */
    public function apply(Builder $query): Builder
    {
        return $query
            ->where('fuel_transactions.transacted_at', '>=', $this->from)
            ->where('fuel_transactions.transacted_at', '<', $this->to)
            ->when($this->cardNo !== null, fn (Builder $q) => $q->whereHas('fuelCard', fn (Builder $card) => $card->where('card_no', $this->cardNo)))
            ->when($this->stationId !== null, fn (Builder $q) => $q->where('fuel_transactions.station_id', $this->stationId))
            ->when($this->companyId !== null, fn (Builder $q) => $q->where('fuel_transactions.company_id', $this->companyId))
            ->when($this->productCode !== null, fn (Builder $q) => $q->whereHas('product', fn (Builder $product) => $product->where('code', $this->productCode)));
    }
}
