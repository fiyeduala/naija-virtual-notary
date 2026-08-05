<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Neutral /dashboard entry point that forwards each user to their
 * role-specific dashboard. Real dashboards are built in later phases.
 */
class DashboardController extends Controller
{
    public function index(): RedirectResponse
    {
        $user = Auth::user();

        return match (true) {
            // Admins work out of the Filament panel — there is no 'admin.dashboard'.
            $user->isAdmin()  => redirect()->route('filament.admin.pages.dashboard'),
            $user->isNotary() => redirect()->route('notary.dashboard'),
            default           => redirect()->route('client.dashboard'),
        };
    }
}
