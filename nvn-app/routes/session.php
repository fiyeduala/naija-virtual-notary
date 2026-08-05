<?php

use App\Http\Controllers\Client\DocumentDownloadController;
use App\Http\Controllers\Session\NotarizeController;
use App\Http\Controllers\Session\SessionController;
use Illuminate\Support\Facades\Route;

/*
| Phase 6 — Verification call + notarization.
| Merge into routes/web.php (or require this file).
*/

Route::middleware(['auth', 'verified.otp'])->group(function () {
    // Verification call (client + notary/admin)
    Route::get('/session/{request}/join', [SessionController::class, 'join'])->name('session.join');
    Route::post('/session/{request}/verify', [SessionController::class, 'verifyIdentity'])->name('session.verify');
    // Notary skips the live call — verifies via uploaded ID, goes straight to notarize
    Route::post('/session/{request}/skip-call', [SessionController::class, 'skipCall'])->name('session.skip-call');

    // Notarization editor (notary/admin side)
    Route::get('/session/{request}/notarize', [NotarizeController::class, 'edit'])->name('session.notarize');
    Route::get('/session/{request}/document', [NotarizeController::class, 'document'])->name('session.document');
    Route::get('/session/{request}/asset/{asset}', [NotarizeController::class, 'asset'])->name('session.asset');
    Route::post('/session/{request}/placements', [NotarizeController::class, 'savePlacements'])->name('session.placements');
    Route::post('/session/{request}/finalize', [NotarizeController::class, 'finalize'])->name('session.finalize');
    Route::get('/session/{request}/done', [NotarizeController::class, 'done'])->name('session.done');

    // Client download
    Route::get('/client/documents/{request}/download', [DocumentDownloadController::class, 'download'])->name('client.documents.download');
});
