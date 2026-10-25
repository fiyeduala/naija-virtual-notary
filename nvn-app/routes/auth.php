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
    // Throttled: each submission sends an OTP email, so an unthrottled loop
    // here is a mail flood aimed at our own sending reputation. Named rather
    // than a bare rate, because the limit is two limits -- one on the address
    // typed and a looser one on the IP. See AppServiceProvider.
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('throttle:signup');

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
    // Guessing a six-digit code is cheap for an attacker and expensive for us
    // (a bcrypt comparison per guess), so the rate is capped here and the
    // number of wrong codes an account may offer is capped in OtpService.
    Route::post('/verify', [VerifyOtpController::class, 'verify'])
        ->middleware('throttle:10,10')->name('verify.submit');
    Route::post('/verify/resend', [VerifyOtpController::class, 'resend'])
        ->middleware('throttle:5,10')->name('verify.resend');

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
