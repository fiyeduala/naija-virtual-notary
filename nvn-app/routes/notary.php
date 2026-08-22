<?php

use App\Http\Controllers\Admin\CredentialDownloadController;
use App\Http\Controllers\Admin\NotarizedDocumentController;
use App\Http\Controllers\Admin\NotaryAssetViewController;
use App\Http\Controllers\Admin\NotaryReviewController;
use App\Http\Controllers\Admin\RequestDocumentController;
use App\Http\Controllers\Notary\NotaryApplicationController;
use App\Http\Controllers\Notary\NotaryProfileController;
use App\Http\Controllers\Notary\OffsiteNotarizationController;
use App\Http\Controllers\Notary\OnboardingFeeController;
use App\Http\Controllers\Webhooks\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Phase 3 — Notary onboarding + Paystack onboarding fee + admin review.
| Merge into routes/web.php (or require this file).
|
| IMPORTANT: exclude the Paystack webhook route from CSRF protection.
| In bootstrap/app.php, inside ->withMiddleware(...):
|     $middleware->validateCsrfTokens(except: ['webhooks/paystack']);
*/

// Partner application (public — marketing page + account creation form in one)
Route::get('/partner-with-us', [NotaryApplicationController::class, 'show'])
    ->name('notary.apply');
Route::post('/partner-with-us', [NotaryApplicationController::class, 'store'])
    ->name('partner');

// Paystack webhook — external caller, CSRF-excluded, no auth
Route::post('/webhooks/paystack', [PaystackWebhookController::class, 'handle'])
    ->name('webhooks.paystack');

// Authenticated + verified notary onboarding
Route::middleware(['auth', 'verified.otp', 'role:notary'])->group(function () {
    // Onboarding fee
    Route::get('/notary/onboarding/fee', [OnboardingFeeController::class, 'show'])->name('notary.onboarding.fee');
    Route::post('/notary/onboarding/fee', [OnboardingFeeController::class, 'pay'])->name('notary.onboarding.pay');
    Route::get('/notary/onboarding/callback', [OnboardingFeeController::class, 'callback'])->name('notary.onboarding.callback');
    Route::get('/notary/onboarding/status', [OnboardingFeeController::class, 'status'])->name('notary.onboarding.status');

    // Profile completion (after approval)
    Route::get('/notary/profile', [NotaryProfileController::class, 'edit'])->name('notary.profile.edit');
    Route::post('/notary/profile/assets', [NotaryProfileController::class, 'saveAssets'])->name('notary.profile.assets');
    Route::post('/notary/profile/bank', [NotaryProfileController::class, 'saveBank'])->name('notary.profile.bank');
    Route::post('/notary/profile/service', [NotaryProfileController::class, 'saveService'])->name('notary.profile.service');
    // Named golive still, because the name is what every blade and redirect
    // holds; what it does behind that name is now ask, not do.
    Route::post('/notary/profile/go-live', [NotaryProfileController::class, 'requestListing'])->name('notary.profile.golive');
});

// Offsite notarization — a job the notary took on themselves, brought here only
// to be sealed. Admin is included because they hold the platform's own notary
// profile and may seal offsite work the same way; every route below scopes to
// the signed-in notary's own profile, so nobody sees anybody else's jobs.
Route::middleware(['auth', 'verified.otp', 'role:notary,admin'])->group(function () {
    Route::get('/notary/offsite', [OffsiteNotarizationController::class, 'index'])->name('notary.offsite.index');
    // Before the {request} route, or "new" is read as an id.
    Route::get('/notary/offsite/new', [OffsiteNotarizationController::class, 'create'])->name('notary.offsite.create');
    Route::post('/notary/offsite', [OffsiteNotarizationController::class, 'store'])->name('notary.offsite.store');
    Route::get('/notary/offsite/{request}', [OffsiteNotarizationController::class, 'show'])->name('notary.offsite.show');
    Route::get('/notary/offsite/{request}/documents/{document}', [OffsiteNotarizationController::class, 'document'])->name('notary.offsite.document');
    Route::post('/notary/offsite/{request}/documents', [OffsiteNotarizationController::class, 'addDocuments'])->name('notary.offsite.documents.add');
    Route::delete('/notary/offsite/{request}/documents/{document}', [OffsiteNotarizationController::class, 'removeDocument'])->name('notary.offsite.documents.remove');
    Route::post('/notary/offsite/{request}/pay', [OffsiteNotarizationController::class, 'pay'])->name('notary.offsite.pay');
    Route::get('/notary/offsite/{request}/callback', [OffsiteNotarizationController::class, 'callback'])->name('notary.offsite.callback');
});

// Admin review
Route::middleware(['auth', 'verified.otp', 'role:admin'])->group(function () {
    Route::get('/admin/notaries', [NotaryReviewController::class, 'index'])->name('admin.notaries.index');
    Route::get('/admin/notaries/{notary}', [NotaryReviewController::class, 'show'])->name('admin.notaries.show');
    Route::post('/admin/notaries/{notary}/approve', [NotaryReviewController::class, 'approve'])->name('admin.notaries.approve');
    Route::post('/admin/notaries/{notary}/reject', [NotaryReviewController::class, 'reject'])->name('admin.notaries.reject');
    Route::get('/admin/credentials/{credential}/download', [CredentialDownloadController::class, 'download'])->name('admin.credentials.download');
    Route::get('/admin/assets/{asset}/view', [NotaryAssetViewController::class, 'view'])->name('admin.assets.view');
    Route::get('/admin/requests/{request}/notarized', [NotarizedDocumentController::class, 'view'])->name('admin.requests.notarized');
    Route::get('/admin/requests/{request}/documents/{document}', [RequestDocumentController::class, 'view'])->name('admin.requests.document');
});
