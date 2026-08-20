<?php

use App\Http\Controllers\Client\ClientDashboardController;
use App\Http\Controllers\Client\ClientRequestController;
use App\Http\Controllers\Client\MarketplaceController;
use App\Http\Controllers\Client\RequestCategoryController;
use Illuminate\Support\Facades\Route;

/*
| Phase 4 — Client request flow.
| Merge into routes/web.php (or require this file).
|
| NOTE: this defines the real client.dashboard route. Remove the placeholder
| client dashboard from the Phase 2 routes/auth.php to avoid a name clash.
*/

Route::middleware(['auth', 'verified.otp', 'role:client'])->group(function () {
    // Dashboard (replaces the Phase 2 placeholder)
    Route::get('/client', [ClientDashboardController::class, 'index'])->name('client.dashboard');

    // Intake
    Route::get('/client/request/new', [ClientRequestController::class, 'create'])->name('client.request.create');
    Route::post('/client/request', [ClientRequestController::class, 'store'])->name('client.request.store');
    Route::get('/client/request/{request}/review', [ClientRequestController::class, 'review'])->name('client.request.review');

    // Marketplace + selection + scheduling
    Route::get('/client/request/{request}/notaries', [MarketplaceController::class, 'index'])->name('client.marketplace.index');
    Route::get('/client/request/{request}/notaries/{notary}', [MarketplaceController::class, 'show'])->name('client.marketplace.show');
    Route::post('/client/request/{request}/select', [MarketplaceController::class, 'select'])->name('client.marketplace.select');

    // Wrong category — re-pick after the desk has queried it. Deliberately not
    // inside the marketplace routes: those refuse anything past Submitted, and
    // every request that reaches here has already been paid for.
    Route::get('/client/request/{request}/category', [RequestCategoryController::class, 'show'])->name('client.request.category.show');
    Route::post('/client/request/{request}/category', [RequestCategoryController::class, 'update'])->name('client.request.category.update');
});
