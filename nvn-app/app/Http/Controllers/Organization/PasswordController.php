<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Forgot-password for a partner body's portal.
 *
 * The same broker machinery as the client one, on its own token table (see
 * config/auth.php `passwords.organizations`), and with the same two rules: the
 * reply is identical whether or not the address is on file, and the request is
 * throttled per address and IP so it cannot be used to bury an inbox.
 *
 * An admin can also issue a password directly from the panel. That path exists
 * for a body that has lost the inbox as well as the password; this one is for
 * the ordinary case, where nobody at the platform need be involved at all.
 */
class PasswordController extends Controller
{
    private const BROKER = 'organizations';

    public function request(): View
    {
        return view('organizations.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        $key = 'org-password-reset|' . Str::lower($request->input('email')) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'email' => 'Too many reset requests. Please try again in '
                    . RateLimiter::availableIn($key) . ' seconds.',
            ]);
        }

        RateLimiter::hit($key, 900);

        Password::broker(self::BROKER)->sendResetLink($request->only('email'));

        return back()->with('status',
            'If that address has a partner portal, a reset link is on its way.');
    }

    public function reset(Request $request, string $token): View
    {
        return view('organizations.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $status = Password::broker(self::BROKER)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Organization $organization, string $password) {
                $organization->forceFill([
                    'password'       => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                AuditLogger::record('organization.portal_password_reset', 'organization',
                    $organization->id, ['by' => 'self-service']);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'That reset link is invalid or has expired. Ask for a new one below.',
            ]);
        }

        return redirect()->route('organization.login')
            ->with('status', 'Your password has been reset. Sign in with the new one.');
    }
}
