<?php

namespace App\Auth;

use App\Support\AuditLogger;
use App\Support\WordPressHasher;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The normal Eloquent provider, plus one fallback: an account carried over
 * from the old WordPress site may still be holding a WordPress password hash.
 *
 * This is the whole reason a migrated user does not have to reset anything.
 * They type the password they have always used; it fails the Laravel check
 * (there is no Laravel hash yet), passes the WordPress check, and is
 * immediately re-hashed the Laravel way. The WordPress hash is deleted in the
 * same write, so it is used at most once per account and the old MD5-based
 * scheme never becomes this application's password storage.
 *
 * Registered as the 'legacy-eloquent' driver in AppServiceProvider. Because
 * every guard goes through the provider, this covers the site's sign-in form,
 * the Filament panel and anything else that calls Auth::attempt.
 */
class LegacyUserProvider extends EloquentUserProvider
{
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        if (parent::validateCredentials($user, $credentials)) {
            return true;
        }

        $legacy = $user->getAttribute('legacy_password');

        if (blank($legacy) || ! isset($credentials['password'])) {
            return false;
        }

        if (! WordPressHasher::check((string) $credentials['password'], $legacy)) {
            return false;
        }

        $this->upgrade($user, (string) $credentials['password']);

        return true;
    }

    /**
     * Both writes happen together. If only the first landed the account would
     * accept the old hash forever; if only the second, the person would be
     * locked out of an account whose password they just proved they knew.
     */
    private function upgrade(Authenticatable $user, string $password): void
    {
        $user->forceFill([
            // 'hashed' cast on the model turns this into a bcrypt hash.
            'password'        => $password,
            'legacy_password' => null,
        ])->save();

        AuditLogger::record('user.legacy_password_upgraded', 'user', $user->getAuthIdentifier(), [
            'source' => $user->getAttribute('legacy_source') ?? 'unknown',
        ], (int) $user->getAuthIdentifier());
    }
}
