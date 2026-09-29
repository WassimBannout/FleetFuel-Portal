<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Readiness check: the application is up and can query its database.
 *
 * Liveness (the app boots at all) is Laravel's built-in /up route.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = $this->databaseStatus();
        $ready = $database === 'ok';

        return response()->json([
            'status' => $ready ? 'ok' : 'unavailable',
            'checks' => [
                'app' => 'ok',
                'database' => $database,
            ],
        ], $ready ? 200 : 503);
    }

    private function databaseStatus(): string
    {
        try {
            DB::connection()->select('select 1');

            return 'ok';
        } catch (Throwable $e) {
            // Details go to the server log only; the response never exposes
            // hosts, usernames or driver messages.
            Log::warning('Readiness check failed: database unavailable.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return 'unavailable';
        }
    }
}
