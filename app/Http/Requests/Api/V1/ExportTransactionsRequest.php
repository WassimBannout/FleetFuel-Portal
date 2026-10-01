<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Http\Requests\Reports\ExportTransactionsRequest as WebExportTransactionsRequest;
use Illuminate\Validation\Validator;

/**
 * GET /api/v1/exports/transactions.csv: the web download's filters, plus
 * the API rule that unknown query parameters (page, per_page...) are
 * refused rather than ignored.
 */
class ExportTransactionsRequest extends WebExportTransactionsRequest
{
    use RejectsUnknownFields {
        after as rejectUnknownFields;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [...$this->rejectUnknownFields(), $this->dateRangeLimit()];
    }
}
