<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Forgot-password / reset-password.
 *
 * Uses Laravel's standard password broker (see config/auth.php `passwords`),
 * so tokens are hashed, single-use and expire after 60 minutes.
 *
 * Two deliberate choices:
 *  - The "we sent you a link" response is identical whether or not the email
 *    exists. Anything else turns this form into an account-enumeration oracle.
 *  - A successful reset also marks the email verified. Clicking a link sent to
 *    that inbox proves ownership just as well as the signup OTP does, and
 *    without this a user who reset would be bounced straight to /verify with a
 *    code they never asked for.
 */
class PasswordResetController extends Controller
{
    /** Step 1 — ask for the email address. */
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    /** Step 2 — email the reset link. */
    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        // Throttled per email + IP, same shape as the login limiter, so this
        // cannot be used to spam someone's inbox.
        $key = 'password-reset|' . Str::lower($request->input('email')) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'email' => 'Too many reset requests. Please try again in '
                    . RateLimiter::availableIn($key) . ' seconds.',
            ]);
        }

        RateLimiter::hit($key, 900);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            AuditLogger::record('password.reset_requested', 'user', null, [
                'email' => $request->input('email'),
            ]);
        }

        // Same message either way — never reveal whether the address is registered.
        return back()->with('status', 'If that email is on file, a reset link is on its way. Check your inbox.');
    }

    /** Step 3 — the form behind the emailed link. */
    public function reset(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    /** Step 4 — store the new password. */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => $password,
                    'remember_token' => Str::random(60),
                    // See the class docblock: proving inbox access is enough.
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                AuditLogger::record('password.reset', 'user', $user->id, [], $user->id);

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'That reset link is invalid or has expired. Request a new one below.',
            ]);
        }

        return redirect()->route('login')
            ->with('status', 'Your password has been reset. Sign in with your new password.');
    }
}
