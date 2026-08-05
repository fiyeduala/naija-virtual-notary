<?php

use App\Http\Controllers\Client\RequestPaymentController;
use App\Http\Controllers\Notary\NotaryRequestController;
use Illuminate\Support\Facades\Route;

/*
| Phase 5 — Payment gate + notary accept/decline.
| Merge into routes/web.php (or require this file).
|
| The Paystack webhook route itself is unchanged from Phase 3 (still
| /webhooks/paystack) — just replace the controller with the Phase 5 version
| that routes request_fee payments. CSRF exclusion stays in place.
*/

// Client — request payment
Route::middleware(['auth', 'verified.otp', 'role:client'])->group(function () {
    Route::post('/client/request/{request}/pay', [RequestPaymentController::class, 'pay'])
        ->name('client.request.payment.pay');
    Route::get('/client/request/{request}/payment/callback', [RequestPaymentController::class, 'callback'])
        ->name('client.request.payment.callback');
    Route::get('/client/request/{request}/payment/status', [RequestPaymentController::class, 'status'])
        ->name('client.request.payment.status');
});

// Notary — incoming paid requests, accept / decline.
// Admin is included: they run the platform's own notary desk. Every action here
// still passes through authorizeNotary(), so an admin only ever sees requests
// assigned to the system-native profile or handed to them by the fallback.
Route::middleware(['auth', 'verified.otp', 'role:notary,admin'])->group(function () {
    Route::get('/notary/requests', [NotaryRequestController::class, 'incoming'])->name('notary.requests.incoming');
    Route::get('/notary/requests/{request}', [NotaryRequestController::class, 'show'])->name('notary.requests.show');
    Route::get('/notary/requests/{request}/documents/{document}', [NotaryRequestController::class, 'document'])->name('notary.requests.document');
    Route::post('/notary/requests/{request}/accept', [NotaryRequestController::class, 'accept'])->name('notary.requests.accept');
    Route::post('/notary/requests/{request}/decline', [NotaryRequestController::class, 'decline'])->name('notary.requests.decline');
});
