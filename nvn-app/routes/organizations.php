<?php

use App\Http\Controllers\Organization\AuthController;
use App\Http\Controllers\Organization\DashboardController;
use App\Http\Controllers\Organization\PasswordController;
use App\Http\Controllers\OrganizationApplicationController;
use App\Http\Controllers\OrganizationLandingController;
use Illuminate\Support\Facades\Route;

/*
| Organizations — partner bodies with their own link, price and arrangement.
|
| Three separate doors, and they are deliberately not interchangeable:
|
|   public   — the landing page a body hands to its applicants. It sets the
|              referral cookie and then gets out of the way; everything after
|              it is the ordinary client flow.
|   apply    — a body asking to partner. Unauthenticated and throttled.
|   portal   — the body's own numbers, behind its own `organization` guard, so
|              nothing about client or notary sign-in is touched.
|
| The {organization:slug} route is last among the /organizations paths on
| purpose: `apply` and the code box are literal segments and must match before
| a slug ever gets the chance to swallow them.
*/

// The body's own portal. Its own guard, so an organization can never hold a
// session on the client side of the site and vice versa.
Route::prefix('organizations')->name('organization.')->group(function () {
    Route::middleware('guest:organization')->group(function () {
        Route::get('/login', [AuthController::class, 'show'])->name('login');
        // Named, because an unnamed route in a named group takes the group's
        // prefix as its whole name — leaving a route called `organization.`
        // that nothing means to reference.
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:10,1')->name('login.attempt');

        Route::get('/forgot-password', [PasswordController::class, 'request'])->name('password.request');
        Route::post('/forgot-password', [PasswordController::class, 'email'])->name('password.email');
        Route::get('/reset-password/{token}', [PasswordController::class, 'reset'])->name('password.reset');
        Route::post('/reset-password', [PasswordController::class, 'update'])->name('password.update');
    });

    Route::middleware('auth:organization')->group(function () {
        Route::get('/portal', [DashboardController::class, 'index'])->name('portal');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });

    // Applying to partner. The form is public, so the POST is throttled.
    Route::get('/apply', [OrganizationApplicationController::class, 'show'])->name('apply.show');
    Route::post('/apply', [OrganizationApplicationController::class, 'store'])
        ->middleware('throttle:5,60')->name('apply.store');
});

// Somewhere to type a code that arrived by telephone or on paper.
Route::get('/organizations', [OrganizationLandingController::class, 'code'])->name('organization.code');
Route::post('/organizations', [OrganizationLandingController::class, 'redeem'])->name('organization.redeem');

// The link itself. Registered after everything above so no literal path can be
// captured as a slug.
Route::get('/organizations/{slug}', [OrganizationLandingController::class, 'show'])
    ->name('organization.landing');

// The short form, for a letter or a poster.
Route::get('/o/{slug}', [OrganizationLandingController::class, 'short'])->name('organization.short');
