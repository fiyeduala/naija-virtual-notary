<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotaryProfile;
use App\Notifications\NotaryApplicationReviewed;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin review of notary applications. (A richer version lives in the Filament
 * admin panel in a later phase; this is the functional core.)
 */
class NotaryReviewController extends Controller
{
    public function index(): View
    {
        $pending = NotaryProfile::with('user', 'credentials')
            ->where('verification_status', 'pending')
            ->whereNotNull('onboarding_fee_paid_at') // fee paid, ready to review
            ->latest()
            ->get();

        return view('admin.notaries.index', ['pending' => $pending]);
    }

    public function show(NotaryProfile $notary): View
    {
        $notary->load('user', 'credentials');

        return view('admin.notaries.show', ['notary' => $notary]);
    }

    public function approve(NotaryProfile $notary): RedirectResponse
    {
        $notary->update([
            'verification_status' => 'approved',
            'approved_at'         => now(),
            'approved_by'         => auth()->id(),
        ]);
        $notary->user->update(['status' => 'active']);

        AuditLogger::record('notary.approved', 'notary_profile', $notary->id);
        $notary->user->notify(new NotaryApplicationReviewed('approved'));

        return redirect()->route('admin.notaries.index')
            ->with('status', $notary->user->full_name . ' approved.');
    }

    public function reject(NotaryProfile $notary, Request $request): RedirectResponse
    {
        $request->validate(['notes' => ['required', 'string', 'max:2000']]);

        $notary->update([
            'verification_status' => 'rejected',
            'review_notes'        => $request->input('notes'),
        ]);

        AuditLogger::record('notary.rejected', 'notary_profile', $notary->id, [
            'notes' => $request->input('notes'),
        ]);
        $notary->user->notify(new NotaryApplicationReviewed('rejected', $request->input('notes')));

        return redirect()->route('admin.notaries.index')
            ->with('status', 'Application rejected and applicant notified.');
    }
}
