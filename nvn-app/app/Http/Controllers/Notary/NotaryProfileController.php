<?php

namespace App\Http\Controllers\Notary;

use App\Http\Controllers\Controller;
use App\Models\NotaryAsset;
use App\Models\NotaryBankDetail;
use App\Models\NotaryService;
use App\Services\BankAccountService;
use App\Services\PaystackService;
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

        AuditLogger::record('notary.assets_saved', 'notary_profile', $profile->id);

        return back()->with('status', 'Notarial assets saved.');
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

    /** Once assets + bank + at least one service exist, list publicly. */
    public function goLive(): RedirectResponse
    {
        $profile = Auth::user()->notaryProfile->loadCount('services')->load('bankDetails', 'assets');

        // Signature, stamp and seal, each with a file behind it — the same test
        // an admin's List toggle applies and the same one the editor applies
        // when it decides whose seal to offer. Counting rows was not the same
        // question: four rows can still be missing the seal.
        $hasAssets = $profile->canSeal();
        // A bank code, not merely a row: an account saved before the payout
        // rework has a typed bank name that nothing can be transferred to.
        // Verification itself is not required — it can fail for reasons that
        // are not the notary's fault, and an admin can re-run it.
        $hasBank   = $profile->bankDetails?->bank_code !== null;
        $hasService = $profile->services_count > 0;

        if (! ($hasAssets && $hasBank && $hasService)) {
            return back()->withErrors([
                'profile' => 'Complete your assets, bank details, and at least one service before going live.',
            ]);
        }

        $profile->update(['public_listing_enabled' => true]);
        AuditLogger::record('notary.listed', 'notary_profile', $profile->id);

        return redirect()->route('notary.dashboard')
            ->with('status', 'Your profile is now live in the marketplace.');
    }
}
