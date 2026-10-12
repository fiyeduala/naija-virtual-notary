<?php

use App\Http\Controllers\Candidate\AssessmentController;
use App\Http\Controllers\Candidate\AuthController;
use App\Http\Controllers\Candidate\DashboardController;
use App\Http\Controllers\Candidate\DocumentController;
use App\Http\Controllers\Candidate\InterviewController;
use App\Http\Controllers\Candidate\OfferController;
use App\Http\Controllers\Candidate\OnboardingController;
use App\Http\Controllers\Admin\RecruitmentDocumentController;
use App\Http\Controllers\Careers\ApplicationController;
use App\Http\Controllers\Careers\CareersController;
use Illuminate\Support\Facades\Route;

/*
| Recruitment — the careers pages, the application form, and the candidate area.
|
| Three bands, and the boundaries between them are the point:
|
|   public     — /careers and /careers/{slug}. No session of any kind. A closed
|                vacancy still renders; it just cannot be applied to, and that
|                is enforced in the controller rather than by hiding a button.
|   sign-in    — requesting a link and redeeming it. The redemption is a POST
|                behind a confirmation page, because a mail scanner that
|                prefetches the GET would otherwise burn the candidate's only
|                token before they ever clicked it.
|   candidate  — everything behind auth:candidate. A candidate session is on its
|                own guard and reaches no client or staff route anywhere on the
|                platform.
|
| Every candidate route that names a record takes the record and then checks it
| belongs to the signed-in candidate. The id in the URL is never the authority.
*/

/* ---- public ---------------------------------------------------------- */

Route::get('/careers', [CareersController::class, 'index'])->name('careers.index');

// The application form and its POST sit under the vacancy, so a shared link
// carries its own role with it.
Route::get('/careers/{vacancy:slug}', [CareersController::class, 'show'])->name('careers.show');

Route::get('/careers/{vacancy:slug}/apply', [ApplicationController::class, 'create'])
    ->name('careers.apply');

// Throttled because it is an unauthenticated form that writes a row and a
// file. The duplicate rule itself is a database constraint, not this limit.
Route::post('/careers/{vacancy:slug}/apply', [ApplicationController::class, 'store'])
    ->middleware('throttle:6,60')
    ->name('careers.apply.store');

Route::get('/careers/{vacancy:slug}/applied/{reference}', [ApplicationController::class, 'done'])
    ->name('careers.applied');

// The recruitment privacy notice, linked from the form and from every email.
Route::get('/careers-privacy', [CareersController::class, 'privacy'])->name('careers.privacy');

/* ---- candidate sign-in ----------------------------------------------- */

Route::prefix('candidate')->name('candidate.')->group(function () {
    Route::middleware('guest:candidate')->group(function () {
        Route::get('/sign-in', [AuthController::class, 'show'])->name('login');

        // Throttled twice over: by address and by IP, inside the controller.
        Route::post('/sign-in', [AuthController::class, 'request'])
            ->middleware('throttle:6,10')
            ->name('login.request');

        // The GET only shows a page with a button on it. It never consumes the
        // token, so a link scanner cannot spend it.
        Route::get('/sign-in/{token}', [AuthController::class, 'confirm'])
            ->middleware('throttle:20,10')
            ->name('login.confirm');

        // This is what spends it.
        Route::post('/sign-in/{token}', [AuthController::class, 'redeem'])
            ->middleware('throttle:10,10')
            ->name('login.redeem');
    });

    Route::middleware(['auth:candidate', 'candidate.session'])->group(function () {
        Route::post('/sign-out', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', [DashboardController::class, 'index'])->name('home');
        Route::get('/applications/{application}', [DashboardController::class, 'show'])
            ->name('application');

        Route::post('/applications/{application}/withdraw', [DashboardController::class, 'withdraw'])
            ->name('withdraw');

        // The assessment: a draft save, and one final submission.
        Route::get('/assessments/{assignment}', [AssessmentController::class, 'show'])
            ->name('assessment');
        Route::post('/assessments/{assignment}/draft', [AssessmentController::class, 'draft'])
            ->name('assessment.draft');
        Route::post('/assessments/{assignment}', [AssessmentController::class, 'submit'])
            ->name('assessment.submit');

        Route::post('/interviews/{interview}/acknowledge', [InterviewController::class, 'acknowledge'])
            ->name('interview.acknowledge');
        Route::post('/interviews/{interview}/reschedule', [InterviewController::class, 'reschedule'])
            ->name('interview.reschedule');

        Route::get('/offers/{offer}', [OfferController::class, 'show'])->name('offer');
        Route::post('/offers/{offer}/respond', [OfferController::class, 'respond'])
            ->name('offer.respond');

        Route::post('/checks/{item}/upload', [OnboardingController::class, 'upload'])
            ->name('checks.upload');
        Route::post('/checks/{item}/answer', [OnboardingController::class, 'answer'])
            ->name('checks.answer');

        // Authorized per request against this candidate and this file. There
        // is no signed URL and no id-as-password route to a document anywhere.
        Route::get('/documents/{document}', [DocumentController::class, 'show'])
            ->name('document');
    });
});

/* ---- staff ----------------------------------------------------------- */

/*
| One route, and it is here rather than in notary.php because it belongs to
| recruitment. `role:admin` is deliberately absent: a reviewer with a
| recruitment role is not an administrator, and the decision that matters is
| ApplicationDocument::readableBy(), which the controller reaches through the
| service. Identity and guarantor files need sensitive.view on top of that.
*/
Route::middleware(['auth', 'verified.otp'])->group(function () {
    Route::get('/admin/recruitment/documents/{document}', [RecruitmentDocumentController::class, 'download'])
        ->name('admin.recruitment.document');
});
