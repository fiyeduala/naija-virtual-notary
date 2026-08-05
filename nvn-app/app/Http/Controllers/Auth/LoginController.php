<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->ensureIsNotRateLimited();

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            RateLimiter::hit($request->throttleKey(), 60);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($request->throttleKey());
        $request->session()->regenerate();

        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        AuditLogger::record('user.login', 'user', $user->id, [], $user->id);

        // Unverified accounts go to verification first.
        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verify.show');
        }

        return redirect()->intended($this->redirectFor($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** Role-based landing route after login. */
    private function redirectFor($user): string
    {
        return match (true) {
            // Admins work out of the Filament panel; there is no 'admin.dashboard'
            // route, and naming one here threw a 500 on every admin login.
            $user->isAdmin()  => route('filament.admin.pages.dashboard'),
            $user->isNotary() => route('notary.dashboard'),
            default           => route('client.dashboard'),
        };
    }
}
