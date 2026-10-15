<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Services\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class VerifyOtpController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function show(): View|RedirectResponse
    {
        $user = Auth::user();

        if ($user && $user->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        return view('auth.verify', ['email' => $user?->email]);
    }

    public function verify(VerifyOtpRequest $request): RedirectResponse
    {
        $user = Auth::user();

        // Asked before verifying as well as after, so somebody who is already
        // locked out is told why rather than being left guessing at a code the
        // service has stopped reading.
        if ($this->otp->isLockedOut($user)) {
            return back()->withErrors(['code' => $this->lockoutMessage($user)]);
        }

        if (! $this->otp->verify($user, $request->validated('code'))) {
            if ($this->otp->isLockedOut($user)) {
                return back()->withErrors(['code' => $this->lockoutMessage($user)]);
            }

            return back()->withErrors([
                'code' => 'That code is invalid or has expired. Please try again or resend.',
            ]);
        }

        return redirect()->route('dashboard')
            ->with('status', 'Your account is verified.');
    }

    public function resend(): RedirectResponse
    {
        $user = Auth::user();

        if ($this->otp->isLockedOut($user)) {
            return back()->withErrors(['code' => $this->lockoutMessage($user)]);
        }

        if (! $this->otp->canResend($user)) {
            return back()->with('status', 'Please wait a moment before requesting another code.');
        }

        $this->otp->issue($user);

        return back()->with('status', 'A new code has been sent to your email.');
    }

    /**
     * Said in minutes rather than seconds — "try again in 423 seconds" reads
     * like a machine fault, and the number is not precise enough to matter.
     */
    private function lockoutMessage(\App\Models\User $user): string
    {
        $minutes = max(1, (int) ceil($this->otp->availableIn($user) / 60));

        return 'Too many incorrect codes. For your security, please wait '
            . $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes')
            . ' and then request a new code.';
    }
}
