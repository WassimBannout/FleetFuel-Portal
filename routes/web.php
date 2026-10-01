<?php

use App\Http\Controllers\Web\AuditLogController;
use App\Http\Controllers\Web\CompanyController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DeliveryOrderController;
use App\Http\Controllers\Web\DriverController;
use App\Http\Controllers\Web\ExchangeRateController;
use App\Http\Controllers\Web\FuelCardController;
use App\Http\Controllers\Web\ProductController;
use App\Http\Controllers\Web\ProductPriceController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\StationController;
use App\Http\Controllers\Web\StationHomeController;
use App\Http\Controllers\Web\TransactionController;
use App\Http\Controllers\Web\TransactionExportController;
use App\Http\Controllers\Web\VehicleController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::view('/', 'home')->name('home');

// Sign-in and sign-out use Fortify's controllers (throttling, session
// regeneration). These are the only Fortify routes: no registration,
// password reset or two-factor endpoints exist (D16).
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Signed-in, active accounts only. `role:` fences off each role's area;
// scoped queries and policies still decide which records a user may see.
// Route parameters such as {vehicle} or {card} are resolved through the
// signed-in user's tenant scope (AppServiceProvider), so another company's
// record is a 404. There are no delete routes: records are deactivated or
// archived, and the ledger keeps referring to them.
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', DashboardController::class)
        ->middleware('role:admin,company_manager')
        ->name('dashboard');

    Route::get('/station', StationHomeController::class)
        ->middleware('role:station_operator')
        ->name('station.home');

    // The purchase ledger for every role, scoped like the API list: all
    // purchases for admins, the own company for managers, the own station
    // for operators. /results is the same listing for page scripts (JSON).
    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('/transactions/results', [TransactionController::class, 'results'])->name('transactions.results');
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])
        ->whereNumber('transaction')
        ->name('transactions.show');

    // Reference data: every role reads it; only admins change it.
    Route::get('/stations', [StationController::class, 'index'])->name('stations.index');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product}/prices', [ProductPriceController::class, 'index'])->name('products.prices.index');

    Route::middleware('role:admin')->group(function () {
        Route::resource('companies', CompanyController::class)->except(['show', 'destroy']);
        Route::patch('/companies/{company}/status', [CompanyController::class, 'updateStatus'])->name('companies.status');

        Route::resource('stations', StationController::class)->except(['index', 'show', 'destroy']);
        Route::patch('/stations/{station}/active', [StationController::class, 'updateActive'])->name('stations.active');

        Route::resource('products', ProductController::class)->only(['edit', 'update']);
        Route::patch('/products/{product}/active', [ProductController::class, 'updateActive'])->name('products.active');
        Route::post('/products/{product}/prices', [ProductPriceController::class, 'store'])->name('products.prices.store');

        // USD/LBP status and manual overrides. Syncing itself is the
        // scheduled `rates:sync` command, never a page request.
        Route::get('/integrations/exchange-rates', [ExchangeRateController::class, 'index'])->name('integrations.exchange-rates');
        Route::post('/integrations/exchange-rates/overrides', [ExchangeRateController::class, 'store'])->name('integrations.exchange-rates.overrides.store');

        // Read-only audit trail with filters; nothing can edit or delete it.
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
    });

    // Fleet: admins for every company, managers for their own.
    Route::middleware('role:admin,company_manager')->group(function () {
        Route::resource('vehicles', VehicleController::class)->except(['show', 'destroy']);
        Route::patch('/vehicles/{vehicle}/active', [VehicleController::class, 'updateActive'])->name('vehicles.active');

        Route::resource('drivers', DriverController::class)->except(['show', 'destroy']);
        Route::patch('/drivers/{driver}/active', [DriverController::class, 'updateActive'])->name('drivers.active');

        Route::resource('cards', FuelCardController::class)->except(['destroy']);
        Route::patch('/cards/{card}/limits', [FuelCardController::class, 'updateLimits'])->name('cards.limits');
        Route::patch('/cards/{card}/status', [FuelCardController::class, 'updateStatus'])->name('cards.status');

        // Diesel deliveries. Managers request and may cancel their own
        // pending orders; only admins schedule, dispatch and deliver
        // (DeliveryOrderService decides, with the order locked).
        Route::resource('deliveries', DeliveryOrderController::class)->only(['index', 'create', 'store', 'show']);
        Route::get('/deliveries/{delivery}/panel', [DeliveryOrderController::class, 'panel'])->name('deliveries.panel');
        Route::patch('/deliveries/{delivery}/status', [DeliveryOrderController::class, 'updateStatus'])->name('deliveries.status');

        // Reports read ReportRepository through ReportScope, which pins a
        // manager to their own company. The CSV is the API's file, served
        // to the browser session.
        Route::redirect('/reports', '/reports/consumption')->name('reports.index');
        Route::prefix('reports')->name('reports.')->controller(ReportController::class)->group(function () {
            Route::get('/consumption', 'consumption')->name('consumption');
            Route::get('/top-stations', 'topStations')->name('top-stations');
            Route::get('/quota-exceptions', 'quotaExceptions')->name('quota-exceptions');
            Route::get('/anomalies', 'anomalies')->name('anomalies');
            Route::get('/efficiency', 'efficiency')->name('efficiency');
            Route::get('/delivery-sla', 'deliverySla')->name('delivery-sla');
        });
        Route::get('/exports/transactions.csv', TransactionExportController::class)->name('exports.transactions');
    });
});
