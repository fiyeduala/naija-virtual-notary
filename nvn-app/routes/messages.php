<?php

use App\Http\Controllers\Admin\MessageOversightController;
use App\Http\Controllers\Messaging\MessageController;
use Illuminate\Support\Facades\Route;

/*
| Phase 7 — Messaging.
| Merge into routes/web.php (or require this file).
*/

// Client + notary thread (the assigned notary or the admin handling a fallback)
Route::middleware(['auth', 'verified.otp'])->group(function () {
    Route::get('/messages/{request}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('/messages/{request}', [MessageController::class, 'store'])->name('messages.store');
});

// Admin oversight — view all threads, post into any
Route::middleware(['auth', 'verified.otp', 'role:admin'])->group(function () {
    Route::get('/admin/messages', [MessageOversightController::class, 'index'])->name('admin.messages.index');
    Route::get('/admin/messages/{request}', [MessageOversightController::class, 'show'])->name('admin.messages.show');
    Route::post('/admin/messages/{request}', [MessageOversightController::class, 'store'])->name('admin.messages.store');
});
