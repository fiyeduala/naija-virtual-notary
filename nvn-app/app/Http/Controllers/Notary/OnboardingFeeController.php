<?php

namespace App\Http\Controllers\Notary;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Services\PaystackService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class OnboardingFeeController extends Controller
{
    public function __construct(private PaystackService $paystack) {}

    /**
     * Landing page that explains the fee and starts payment.
     *
     * The same page joins a new partner and renews an existing one — it is the
     * same fee buying the same year, and giving renewal its own screen would
     * mean two places to keep the price and the wording in step.
     *
     * A partner who is paid up and nowhere near their renewal date is sent to
     * their status page instead; they have nothing to do here. One who is inside
     * the notice window, or has lapsed, is let through so they can pay again.
     */
    public function show(): View|RedirectResponse
    {
        $profile = Auth::user()->notaryProfile;

        if ($profile?->membershipActive() && ! $profile->membershipEndingSoon()) {
            return redirect()->route('notary.onboarding.status');
        }

        $amount = (int) PlatformSetting::get('onboarding_fee_ngn', config('nvn.onboarding_fee_ngn'));

        return view('notary.onboarding.fee', [
            'amountDisplay' => '₦' . number_format($amount / 100, 2),
            'profile'       => $profile,
            'isRenewal'     => (bool) $profile?->onboarding_fee_paid_at,
        ]);
    }

    /** Initialize the Paystack transaction and redirect to hosted checkout. */
    public function pay(): RedirectResponse
    {
        $user = Auth::user();
        $amount = (int) PlatformSetting::get('onboarding_fee_ngn', config('nvn.onboarding_fee_ngn'));
        $reference = $this->paystack->reference('onb');

        Payment::create([
            'user_id'            => $user->id,
            'type'               => 'onboarding_fee',
            'amount'             => $amount,
            'currency'           => 'NGN',
            'paystack_reference' => $reference,
            'status'             => 'pending',
        ]);

        $init = $this->paystack->initializeTransaction(
            email: $user->email,
            amountMinor: $amount,
            reference: $reference,
            callbackUrl: route('notary.onboarding.callback'),
            currency: 'NGN',
            metadata: ['purpose' => 'onboarding_fee', 'user_id' => $user->id],
        );

        if (! $init['authorization_url']) {
            return back()->withErrors(['payment' => 'Could not start payment. Please try again.']);
        }

        return redirect()->away($init['authorization_url']);
    }

    /**
     * Paystack redirects the user back here after payment. We verify, but the
     * webhook is the authoritative confirmation — this just gives the user
     * immediate feedback.
     */
    public function callback(): RedirectResponse
    {
        $reference = request('reference') ?? request('trxref');

        if (! $reference) {
            return redirect()->route('notary.onboarding.fee')
                ->withErrors(['payment' => 'No payment reference returned.']);
        }

        try {
            $data = $this->paystack->verifyTransaction($reference);
        } catch (\Throwable $e) {
            return redirect()->route('notary.onboarding.status')
                ->with('status', 'We are confirming your payment. This page will update shortly.');
        }

        if ($this->paystack->isSuccessful($data)) {
            $this->markPaid($reference);
        }

        return redirect()->route('notary.onboarding.status');
    }

    public function status(): View
    {
        $profile = Auth::user()->notaryProfile;

        return view('notary.onboarding.status', ['profile' => $profile]);
    }

    /** Shared logic: flip payment + profile to paid (idempotent). */
    public static function markPaid(string $reference): void
    {
        $payment = Payment::where('paystack_reference', $reference)
            ->where('type', 'onboarding_fee')
            ->first();

        if ($payment) {
            static::settle($payment);
        }
    }

    /**
     * Activate the application behind a cleared onboarding fee.
     *
     * Separate from markPaid() so a fee paid by bank transfer into the company
     * account unlocks the applicant in exactly the same way as one paid on the
     * checkout page — there is no second, quieter version of "paid".
     */
    public static function settle(Payment $payment): void
    {
        if ($payment->status === 'successful') {
            return; // already processed
        }

        $payment->update([
            'status'       => 'successful',
            'completed_at' => $payment->completed_at ?? now(),
        ]);

        $profile = $payment->user->notaryProfile;

        if (! $profile) {
            return;
        }

        // The day they joined, written once and never rewritten — it is a fact
        // about the partnership, not a running balance.
        $isRenewal = (bool) $profile->onboarding_fee_paid_at;

        if (! $isRenewal) {
            $profile->update(['onboarding_fee_paid_at' => now()]);
        }

        // Every cleared partner fee buys another year, whether it is the first
        // one or the fifth, and whether it came off the checkout page or out of
        // an admin recording a bank transfer.
        $profile->extendMembership();

        AuditLogger::record(
            $isRenewal ? 'notary.membership_renewed' : 'notary.onboarding_fee_paid',
            'notary_profile',
            $profile->id,
            [
                'reference'  => $payment->paystack_reference,
                'settled'    => $payment->settlement_method ?? 'paystack',
                'expires_at' => $profile->fresh()->membership_expires_at?->toDateString(),
            ],
            $payment->user_id,
        );
    }
}
