<?php

use App\Http\Controllers\Api\V1\CardBalanceController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\FuelCardController;
use App\Http\Controllers\Api\V1\ProductPriceController;
use App\Http\Controllers\Api\V1\StationController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\VehicleController;
use Illuminate\Support\Facades\Route;

// Versioned API (docs/05-API-CONTRACT.md, docs/api/openapi.json). Only
// bearer tokens authenticate here: config/sanctum.php has no session guard,
// so a browser cookie never counts. Token abilities are necessary but never
// sufficient: role middleware, scoped lookups and policies still apply.
// Delivery (M07) and report/export (M08) endpoints are documented as
// planned in the OpenAPI file and not routed yet.

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Public: credentials in, token out; 5 requests/minute per email + IP.
    Route::post('auth/token', [TokenController::class, 'store'])
        ->middleware('throttle:token-issue')
        ->name('auth.token.store');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::delete('auth/token', [TokenController::class, 'destroy'])->name('auth.token.destroy');

        // Reference data for every role.
        Route::middleware('abilities:reference:read')->group(function () {
            Route::get('stations', [StationController::class, 'index'])->name('stations.index');
            Route::get('products/prices', [ProductPriceController::class, 'index'])->name('products.prices');
        });

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

        // Company data: admins and company managers only. {card} is looked
        // up through the caller's tenant scope (AppServiceProvider).
        Route::middleware('role:admin,company_manager')->group(function () {
            Route::patch('cards/{card}', [FuelCardController::class, 'update'])
                ->middleware('abilities:cards:write')
                ->whereNumber('card')
                ->name('cards.update');

            Route::get('vehicles', [VehicleController::class, 'index'])->middleware('abilities:fleet:read')->name('vehicles.index');
            Route::post('vehicles', [VehicleController::class, 'store'])->middleware('abilities:fleet:write')->name('vehicles.store');
            Route::get('drivers', [DriverController::class, 'index'])->middleware('abilities:fleet:read')->name('drivers.index');
            Route::post('drivers', [DriverController::class, 'store'])->middleware('abilities:fleet:write')->name('drivers.store');
        });
    });
});
