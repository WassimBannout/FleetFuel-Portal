<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExportTransactionsRequest;
use App\Services\TransactionCsvExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** GET /api/v1/exports/transactions.csv: the accounting CSV for API clients. */
class TransactionExportController extends Controller
{
    public function __invoke(ExportTransactionsRequest $request, TransactionCsvExport $export): StreamedResponse
    {
        return $export->download($request->actor(), $request->transactionFilters(), $request->filename());
    }
}
