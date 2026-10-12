<?php

namespace App\Filament\Concerns;

/**
 * This resource or page is for platform administrators only.
 *
 * Why it exists: until the recruitment module, `User::canAccessPanel()` was
 * the only gate on the panel and it answered admin-or-nothing, so every
 * resource inside it inherited that answer for free. Recruitment staff now
 * reach the same panel — a reviewer, a coordinator, an HR administrator — and
 * the moment they do, "inherited for free" becomes "visible to everybody who
 * can get in".
 *
 * So the gate moves down a level. Every resource and page that predates
 * recruitment carries this trait and refuses anybody who is not an
 * administrator, which means an HR reviewer invited to mark writing exercises
 * has no route to a payment, a payout, a client's notarized document, a user
 * record or the platform settings. Not a hidden link: `canAccess()` is what
 * Filament checks before registering the navigation item AND before serving
 * the page, so a typed URL gets a 403 exactly as the missing link suggests.
 *
 * The recruitment resources do the opposite and ask
 * App\Support\RecruitmentPermission for the specific ability they need.
 */
trait AdminOnly
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof \App\Models\User && $user->isAdmin();
    }
}
