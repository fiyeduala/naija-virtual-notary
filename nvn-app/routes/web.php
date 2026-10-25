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
// Served from a route, not public/, because the site icon is uploaded at runtime.
Route::get('/manifest.webmanifest', \App\Http\Controllers\ManifestController::class)->name('manifest');

Route::get('/', fn () => view('public.home'))->name('home');
Route::get('/about', fn () => view('public.about'))->name('about');
Route::get('/how-it-works', fn () => view('public.how-it-works'))->name('how-it-works');
// /partner-with-us GET+POST are handled in routes/notary.php (NotaryApplicationController)

// Blog. The show route resolves by slug and 404s on a draft for everyone
// except a signed-in admin, so an article can be read in place before it goes
// out. Note /blog-media is a real directory served by the web server — that is
// where cover images live, and why it is not called /blog/anything.
Route::get('/blog', [\App\Http\Controllers\BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{post}', [\App\Http\Controllers\BlogController::class, 'show'])->name('blog.show');

// The read beacon, and comments. Both take the slug and resolve the article
// themselves, because both are public POSTs and neither should accept a
// database id from the body.
//
// The beacon is throttled generously: it fires at most once per article per
// reader, but a reader with several tabs open is normal and should not be
// refused. The comment throttle is named rather than a bare rate: it counts
// the commenter and the address separately, so one busy network does not
// silence everybody on it. See AppServiceProvider.
Route::post('/blog/{post}/read', [\App\Http\Controllers\BlogController::class, 'read'])
    ->middleware('throttle:30,1')
    ->name('blog.read');

Route::post('/blog/{post}/comment', [\App\Http\Controllers\PostCommentController::class, 'store'])
    ->middleware('throttle:comment')
    ->name('blog.comment');
// Email preferences — the unsubscribe link at the foot of an announcement.
// Signed rather than authenticated: making someone log in to stop receiving
// email is how you get reported as spam instead.
Route::get('/email/unsubscribe/{user}', [\App\Http\Controllers\EmailPreferencesController::class, 'unsubscribe'])
    ->name('email.unsubscribe')->middleware('signed');
Route::get('/email/resubscribe/{user}', [\App\Http\Controllers\EmailPreferencesController::class, 'resubscribe'])
    ->name('email.resubscribe')->middleware('signed');

// Where a browser posts what the Content-Security-Policy would have blocked.
// Report-only until NVN_CSP_ENFORCE is set, so this is how the policy gets
// corrected before it ever starts enforcing. CSRF-excluded in
// bootstrap/app.php: the browser posts this by itself and carries no token.
// Throttled, and it answers 204 with no body however it is prodded.
Route::post('/csp-report', [\App\Http\Controllers\CspReportController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('csp.report');

require __DIR__ . '/auth.php';      // Phase 2 — auth + OTP + verify + notary placeholder
require __DIR__ . '/notary.php';    // Phase 3 — notary onboarding + admin review + webhook
require __DIR__ . '/client.php';    // Phase 4 — client request flow + real client.dashboard
require __DIR__ . '/payments.php';  // Phase 5 — request payment + notary accept/decline
require __DIR__ . '/session.php';   // Phase 6 — verification call + notarization
require __DIR__ . '/messages.php';  // Phase 7 — messaging + admin oversight
require __DIR__ . '/organizations.php'; // Partner bodies — landing, application, portal
require __DIR__ . '/careers.php';   // Recruitment — careers pages, applications, candidate area
