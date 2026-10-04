<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sign-in for a partner body's own portal.
 *
 * Its own guard, so nothing about client or notary sign-in changes and an
 * organization can never end up holding a session on the main site. There is
 * no registration here on purpose: a body exists because an admin approved it,
 * and the only way to a login is through that approval.
 */
class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::guard('organization')->check()) {
            return redirect()->route('organization.portal');
        }

        return view('organizations.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // 'status' is part of the credentials rather than a check afterwards,
        // so there is one query and one answer. A pending application has no
        // password at all, and a paused body must lose its portal at the same
        // moment it loses its link — otherwise "paused" would mean two
        // different things depending on which door you tried.
        $ok = Auth::guard('organization')->attempt([
            'email'    => $credentials['email'],
            'status'   => 'active',
            'password' => $credentials['password'],
        ], $request->boolean('remember'));

        if (! $ok) {
            throw ValidationException::withMessages([
                'email' => 'Those details do not match an active partnership.',
            ]);
        }

        $request->session()->regenerate();

        $organization = Auth::guard('organization')->user();
        $organization->forceFill(['last_login_at' => now()])->save();

        AuditLogger::record('organization.signed_in', 'organization', $organization->id, []);

        return redirect()->intended(route('organization.portal'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('organization')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('organization.login')->with('status', 'Signed out.');
    }
}
