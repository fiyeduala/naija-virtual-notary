<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\VerifyOtpController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Notary\NotaryDashboardController;
use App\Http\Controllers\Push\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
| Phase 2 — Authentication routes.
| Merge these into routes/web.php (or include this file from there).
| Placeholder dashboard routes are included so role redirects resolve;
| replace them with the real dashboards in later phases.
*/

// Guest-only
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    // Password reset — Laravel's standard broker (config/auth.php `passwords`).
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

// Authenticated, but email may still be unverified
Route::middleware('auth')->group(function () {
    Route::get('/verify', [VerifyOtpController::class, 'show'])->name('verify.show');
    Route::post('/verify', [VerifyOtpController::class, 'verify'])->name('verify.submit');
    Route::post('/verify/resend', [VerifyOtpController::class, 'resend'])->name('verify.resend');

    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});

// Authenticated + verified
Route::middleware(['auth', 'verified.otp'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Admins are allowed in as the platform's own notary — they take fallback
    // jobs and marketplace bookings of the system-native profile.
    Route::middleware('role:notary,admin')->get('/notary', [NotaryDashboardController::class, 'index'])->name('notary.dashboard');

    // Web Push subscription management
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'subscribe'])->name('push.subscribe');
    Route::delete('/push/unsubscribe', [PushSubscriptionController::class, 'unsubscribe'])->name('push.unsubscribe');
});
