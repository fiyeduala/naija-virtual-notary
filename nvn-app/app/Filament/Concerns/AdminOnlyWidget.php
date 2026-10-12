<?php

namespace App\Filament\Concerns;

/**
 * The widget form of App\Filament\Concerns\AdminOnly.
 *
 * Separate because Filament asks a widget `canView()` and a resource
 * `canAccess()`, and a resource already has its own `canView(Model $record)`
 * with a different signature — one trait defining both would collide with it.
 *
 * A dashboard widget is a small thing that quietly carries real figures:
 * revenue today, payouts owed, the notary desk queue. None of that is a
 * recruitment reviewer's business, so each one refuses anybody who is not an
 * administrator rather than relying on the dashboard itself being admin-only.
 */
trait AdminOnlyWidget
{
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof \App\Models\User && $user->isAdmin();
    }
}
