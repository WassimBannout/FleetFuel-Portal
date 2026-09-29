<?php

use App\Http\Controllers\Api\V1\CardBalanceController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\TransactionController;
use Illuminate\Support\Facades\Route;

// Versioned API (docs/05-API-CONTRACT.md). Only bearer tokens authenticate
// here: config/sanctum.php has no session guard, so a browser cookie never
// counts. Token abilities are necessary but never sufficient: role
// middleware, scoped lookups and policies still apply. Other endpoints
// arrive in M06-M08.

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Public: credentials in, token out; 5 requests/minute per email + IP.
    Route::post('auth/token', [TokenController::class, 'store'])
        ->middleware('throttle:token-issue')
        ->name('auth.token.store');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::delete('auth/token', [TokenController::class, 'destroy'])->name('auth.token.destroy');

        // POS ingestion: station operators only, 60 requests/minute per station.
        Route::post('transactions', [TransactionController::class, 'store'])
            ->middleware(['role:station_operator', 'abilities:transactions:create', 'throttle:pos-writes'])
            ->name('transactions.store');

        Route::middleware('abilities:transactions:read')->group(function () {
            Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
            Route::get('transactions/{transaction}', [TransactionController::class, 'show'])
                ->whereNumber('transaction')
                ->name('transactions.show');
        });

        Route::get('cards/{card_no}/balance', [CardBalanceController::class, 'show'])
            ->middleware('abilities:cards:read')
            ->where('card_no', '[A-Za-z0-9-]{1,40}')
            ->name('cards.balance');
    });
});
