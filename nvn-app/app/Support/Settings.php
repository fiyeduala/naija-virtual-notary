<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * Read-side accessor for the values the admin edits on Platform settings.
 *
 * PlatformSettingsPage writes these to the platform_settings table, but every
 * consumer used to read config('nvn.*') — the static config file — so editing
 * them in the panel changed nothing. Everything that cares now goes through
 * here: the stored value if there is one, the config file as the fallback.
 *
 * The lookups are memoised per request; these are read on nearly every page.
 */
class Settings
{
    /** @var array<string, mixed> */
    private static array $cache = [];

    public static function int(string $key, int $default): int
    {
        return (int) static::remember($key, $default);
    }

    public static function bool(string $key, bool $default): bool
    {
        return filter_var(static::remember($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether the platform may send notary payouts through Paystack Transfers.
     *
     * When false the payout ledger is unchanged — what each notary is owed is
     * still tracked and generated — but the money is moved by hand and recorded
     * against the payout, rather than debited from the Paystack balance.
     */
    public static function paystackTransfersEnabled(): bool
    {
        return static::bool('paystack_transfers', (bool) config('nvn.paystack_transfers', false));
    }

    /**
     * Minutes a partner notary has to respond to a paid request.
     *
     * This is an SLA clock, not a lock: since 2026-08-05 nothing is taken away
     * from the notary when it elapses. It only tells the client and the admin
     * how long a request has been sitting unanswered.
     */
    public static function fallbackMinutes(): int
    {
        return static::int('fallback_minutes', (int) config('nvn.fallback_minutes', 30));
    }

    /** Platform's percentage cut applied to a new notary's profile. */
    public static function defaultCommissionRate(): int
    {
        return static::int('default_commission_rate', (int) config('nvn.default_commission_rate', 50));
    }

    public static function string(string $key, string $default): string
    {
        return trim((string) static::remember($key, $default));
    }

    /**
     * Tawk.to live chat, as [property_id, widget_id].
     *
     * Both must be present for the widget to load — a property ID on its own
     * points at nothing.
     */
    public static function tawk(): array
    {
        $property = static::string('tawk_property_id', (string) config('nvn.tawk.property_id', ''));
        $widget   = static::string('tawk_widget_id', (string) config('nvn.tawk.widget_id', 'default'));

        return $property === '' || $widget === '' ? ['', ''] : [$property, $widget];
    }

    public static function liveChatEnabled(): bool
    {
        return static::tawk()[0] !== '';
    }

    /** Drop the memo — used by the settings page right after a save. */
    public static function flush(): void
    {
        static::$cache = [];
    }

    private static function remember(string $key, mixed $default): mixed
    {
        return static::$cache[$key] ??= PlatformSetting::get($key, $default);
    }
}
