<?php

use App\Http\Controllers\Web\CompanyController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DriverController;
use App\Http\Controllers\Web\FuelCardController;
use App\Http\Controllers\Web\ProductController;
use App\Http\Controllers\Web\StationController;
use App\Http\Controllers\Web\StationHomeController;
use App\Http\Controllers\Web\TransactionController;
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

    Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])
        ->whereNumber('transaction')
        ->name('transactions.show');

    // Reference data: every role reads it; only admins change it.
    Route::get('/stations', [StationController::class, 'index'])->name('stations.index');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');

    Route::middleware('role:admin')->group(function () {
        Route::resource('companies', CompanyController::class)->except(['show', 'destroy']);
        Route::patch('/companies/{company}/status', [CompanyController::class, 'updateStatus'])->name('companies.status');

        Route::resource('stations', StationController::class)->except(['index', 'show', 'destroy']);
        Route::patch('/stations/{station}/active', [StationController::class, 'updateActive'])->name('stations.active');

        Route::resource('products', ProductController::class)->only(['edit', 'update']);
        Route::patch('/products/{product}/active', [ProductController::class, 'updateActive'])->name('products.active');
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
    });
});
