<?php

use App\Http\Controllers\Api\V1\TokenController;
use Illuminate\Support\Facades\Route;

// Versioned API (docs/05-API-CONTRACT.md). Only bearer tokens authenticate
// here: config/sanctum.php has no session guard, so a browser cookie never
// counts. Other endpoints arrive in M05-M08.

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Public: credentials in, token out; 5 requests/minute per email + IP.
    Route::post('auth/token', [TokenController::class, 'store'])
        ->middleware('throttle:token-issue')
        ->name('auth.token.store');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::delete('auth/token', [TokenController::class, 'destroy'])->name('auth.token.destroy');
    });
});
