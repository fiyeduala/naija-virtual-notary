<?php

use Illuminate\Support\Facades\Route;

/*
| Naija Virtual Notary — master web routes.
|
| Each phase contributed a routes file; they're included here in order. A fresh
| Laravel install ships its own routes/web.php — replace it with this one (the
| phase files live alongside it in routes/).
*/

// Public marketing pages
Route::get('/', fn () => view('public.home'))->name('home');
Route::get('/about', fn () => view('public.about'))->name('about');
Route::get('/how-it-works', fn () => view('public.how-it-works'))->name('how-it-works');
// /partner-with-us GET+POST are handled in routes/notary.php (NotaryApplicationController)

// Email preferences — the unsubscribe link at the foot of an announcement.
// Signed rather than authenticated: making someone log in to stop receiving
// email is how you get reported as spam instead.
Route::get('/email/unsubscribe/{user}', [\App\Http\Controllers\EmailPreferencesController::class, 'unsubscribe'])
    ->name('email.unsubscribe')->middleware('signed');
Route::get('/email/resubscribe/{user}', [\App\Http\Controllers\EmailPreferencesController::class, 'resubscribe'])
    ->name('email.resubscribe')->middleware('signed');

require __DIR__ . '/auth.php';      // Phase 2 — auth + OTP + verify + notary placeholder
require __DIR__ . '/notary.php';    // Phase 3 — notary onboarding + admin review + webhook
require __DIR__ . '/client.php';    // Phase 4 — client request flow + real client.dashboard
require __DIR__ . '/payments.php';  // Phase 5 — request payment + notary accept/decline
require __DIR__ . '/session.php';   // Phase 6 — verification call + notarization
require __DIR__ . '/messages.php';  // Phase 7 — messaging + admin oversight
