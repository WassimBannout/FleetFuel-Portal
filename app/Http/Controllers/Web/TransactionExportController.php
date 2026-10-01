<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ExportTransactionsRequest;
use App\Services\TransactionCsvExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The accounting CSV for signed-in admins and managers, the same file the
 * API serves (no bearer token is ever put in the browser).
 */
class TransactionExportController extends Controller
{
    public function __invoke(ExportTransactionsRequest $request, TransactionCsvExport $export): StreamedResponse
    {
        return $export->download($request->actor(), $request->transactionFilters(), $request->filename());
    }
}
