<?php

namespace App\Support;

use App\Models\FuelTransaction;

/**
 * The outcome of a POS request that was accepted: either a new ledger row
 * (201) or the original row of an identical earlier request (200, replay).
 */
final readonly class IngestResult
{
    public function __construct(
        public FuelTransaction $transaction,
        public bool $replayed,
    ) {}
}
