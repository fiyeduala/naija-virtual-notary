<?php

namespace App\Http\Controllers\Notary;

use App\Http\Controllers\Controller;
use App\Models\NotaryAsset;
use App\Models\NotaryBankDetail;
use App\Models\NotaryService;
use App\Notifications\Admin\NotaryAssetsUploaded;
use App\Notifications\Admin\NotaryListingRequested;
use App\Services\BankAccountService;
use App\Services\PaystackService;
use App\Support\AdminAlert;
use App\Support\AuditLogger;
use App\Support\Banks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Profile completion after approval: notarial assets, bank details, and
 * services priced in BOTH NGN and USD (entered independently per the Build Plan).
 */
class NotaryProfileController extends Controller
{
    public function __construct(private PaystackService $paystack) {}

    public function edit(): View|RedirectResponse
    {
        $profile = Auth::user()->notaryProfile;

        if ($profile->verification_status !== 'approved') {
            return redirect()->route('notary.onboarding.status');
        }

        $profile->load('assets', 'bankDetails', 'services');

        return view('notary.onboarding.profile', ['profile' => $profile]);
    }

    public function saveAssets(Request $request): RedirectResponse
    {
        $request->validate([
            'initials'  => ['required', 'string', 'max:10'],
            'scn'       => ['nullable', 'string', 'max:100'],
            'signature' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:4096'],
            'stamp'     => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:4096'],
            'seal'      => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:4096'],
        ]);

        $profile = Auth::user()->notaryProfile;

        // Asked before the upload overwrites it, because afterwards every
        // notary looks like they always had a full set.
        $replacement = $profile->canSeal();

        // SCN on the profile record
        if ($request->filled('scn')) {
            $profile->update(['scn' => $request->input('scn')]);
        }

        // Typed initials
        NotaryAsset::updateOrCreate(
            ['notary_profile_id' => $profile->id, 'type' => 'initials'],
            ['text_value' => $request->input('initials')],
        );

        // Image assets
        foreach (['signature', 'stamp', 'seal'] as $type) {
            $path = $request->file($type)->store('notary-assets', 'private');
            NotaryAsset::updateOrCreate(
                ['notary_profile_id' => $profile->id, 'type' => $type],
                ['file_url' => $path],
            );
        }

        AuditLogger::record('notary.assets_saved', 'notary_profile', $profile->id, [
            'replacement' => $replacement,
        ]);

        // Tell the desk now rather than at the listing request. These images
        // are the only part of a notary that no automated check can judge, and
        // the sooner a person has seen them the smaller the blast radius when
        // they are wrong.
        AdminAlert::send(new NotaryAssetsUploaded($profile->fresh()->load('assets'), $replacement));

        return back()->with(
            'status',
            'Notarial assets saved. We check every notary’s signature, stamp and seal by hand, '
                . 'so please make sure these are the marks you actually notarize with.',
        );
    }

    public function saveBank(Request $request, BankAccountService $accounts): RedirectResponse
    {
        $validated = $request->validate([
            // A code, not a name — Paystack cannot pay to "GTB".
            'bank_code'      => ['required', 'string', 'max:10', function ($attribute, $value, $fail) {
                if (! Banks::exists($value)) {
                    $fail('Choose your bank from the list.');
                }
            }],
            'account_number' => ['required', 'digits:10'],
            'account_name'   => ['required', 'string', 'max:255'],
        ]);

        $detail = $accounts->save(Auth::user()->notaryProfile, $validated);

        return back()->with('status', $this->bankStatusMessage($detail));
    }

    /** Tell the notary what their bank actually said, not just "saved". */
    private function bankStatusMessage(NotaryBankDetail $detail): string
    {
        if (! $detail->isVerified()) {
            return 'Bank details saved. We could not confirm them with your bank just now — '
                . 'an admin will verify them before your first payout.';
        }

        if ($detail->name_matches === false) {
            return 'Bank details saved. Your bank has this account in the name "'
                . $detail->resolved_account_name . '", which does not match your profile name. '
                . 'That is fine for a chambers or company account, but an admin will check it first.';
        }

        return 'Bank details saved and confirmed with your bank as "'
            . $detail->resolved_account_name . '". You are set up for payouts.';
    }

    public function saveService(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'service_type' => ['required', 'string', 'max:255'],
            // Prices entered in MAJOR units in the form, stored in minor units.
            'price_ngn'    => ['required', 'numeric', 'min:0'],
            'price_usd'    => ['required', 'numeric', 'min:0'],
            'duration'     => ['required', 'integer', 'min:5', 'max:240'],
            'description'  => ['nullable', 'string', 'max:1000'],
        ]);

        $profile = Auth::user()->notaryProfile;

        NotaryService::create([
            'notary_profile_id'          => $profile->id,
            'service_type'               => $validated['service_type'],
            'price_ngn'                  => (int) round($validated['price_ngn'] * 100),
            'price_usd'                  => (int) round($validated['price_usd'] * 100),
            'estimated_duration_minutes' => $validated['duration'],
            'description'                => $validated['description'] ?? null,
            'active'                     => true,
        ]);

        AuditLogger::record('notary.service_added', 'notary_profile', $profile->id);

        return back()->with('status', 'Service added.');
    }

    /**
     * Ask to be listed. It used to list you.
     *
     * A complete profile is now the price of admission to the queue, not to the
     * marketplace. The reason is that completeness is the only thing code can
     * check: every gate here asks whether three files exist, and none of them
     * can ask whether the images on them are a real notary's real stamp and
     * seal. One partner uploaded the wrong ones, listed himself, and a client
     * booked a job nobody could finish — the images were wrong, so neither the
     * partner nor the desk could put a valid mark on the document.
     *
     * So a person looks at them now. It costs a new partner a day; it costs a
     * client who booked a bad listing far more than that.
     */
    public function requestListing(): RedirectResponse
    {
        $profile = Auth::user()->notaryProfile->loadCount('services')->load('bankDetails', 'assets');

        if ($blockers = $profile->listingBlockers()) {
            return back()->withErrors([
                'profile' => 'Not quite ready — ' . implode('; ', $blockers) . '.',
            ]);
        }

        if ($profile->public_listing_enabled) {
            return redirect()->route('notary.dashboard')
                ->with('status', 'You are already listed in the marketplace.');
        }

        // Asking twice is not an error — a notary who replaced a rejected seal
        // has a real reason to ask again, and the alert should fire again so it
        // lands in front of whoever declined it. Only the timestamp moves.
        $alreadyWaiting = $profile->isAwaitingListingReview();

        $profile->update([
            'listing_requested_at' => now(),
            'listing_review_notes' => null,
        ]);

        AuditLogger::record('notary.listing_requested', 'notary_profile', $profile->id, [
            'resubmitted' => $alreadyWaiting,
        ]);

        AdminAlert::send(new NotaryListingRequested($profile));

        return redirect()->route('notary.dashboard')->with(
            'status',
            'Sent for review. We check every notary’s signature, stamp and seal by hand before '
                . 'putting them in front of clients — you will hear from us, usually within a day.',
        );
    }
}
