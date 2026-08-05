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

        if (! $this->otp->verify($user, $request->validated('code'))) {
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

        if (! $this->otp->canResend($user)) {
            return back()->with('status', 'Please wait a moment before requesting another code.');
        }

        $this->otp->issue($user);

        return back()->with('status', 'A new code has been sent to your email.');
    }
}
