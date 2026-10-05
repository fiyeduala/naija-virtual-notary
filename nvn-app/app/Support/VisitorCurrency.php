<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Which currency the person in front of us should be quoted in.
 *
 * A client in Lagos should see naira and a client in London dollars, and
 * neither should have to hunt for a dropdown to get there.
 *
 * The country comes from Cloudflare's `CF-IPCountry` header. Three other ways
 * were available and each is worse:
 *
 *  - the browser's geolocation API asks for a GPS permission most people
 *    decline, and asks for the exact position of a person's house in order to
 *    decide between two currencies;
 *  - a GeoIP database (MaxMind and the like) is a file that silently goes
 *    stale, and wants a licence key and a refresh job to stay accurate;
 *  - a lookup API adds a network round trip to the front of a page load, and
 *    a currency that depends on somebody else's uptime.
 *
 * The header is already there on every request, costs nothing and cannot fail.
 * It does need "IP Geolocation" switched on for the zone in the Cloudflare
 * dashboard, under Network — without that the header is simply absent, which
 * this class treats as "unknown" rather than as "abroad".
 *
 * IT IS ONLY TRUSTED WHEN THE REQUEST CAME THROUGH CLOUDFLARE. The origin is
 * still reachable by its own address — App\Support\CloudflareProxies explains
 * why at length — so anyone who found that address could otherwise send
 * `CF-IPCountry: US` and choose which of two price lists to be charged from.
 * A header that decides money is a header that has to be proven, so it is
 * honoured only when Laravel says the request arrived from a trusted proxy,
 * and ignored otherwise.
 *
 * A detected currency is a default, never a verdict. A Nigerian abroad paying
 * with a naira card and a Lagos client paying with a dollar card both exist,
 * so an explicit choice is remembered and beats the header from then on. That
 * is first-touch in reverse — the newest explicit choice wins — because unlike
 * an ad click, which records a historical fact, this records a live preference.
 */
class VisitorCurrency
{
    /** Where an explicit choice is kept. Session, so it dies with the visit. */
    public const SESSION_KEY = 'nvn_currency';

    /**
     * The currency to quote this visitor in.
     *
     * An explicit choice first, then the country, then naira.
     */
    public static function for(Request $request): string
    {
        $chosen = $request->session()?->get(self::SESSION_KEY);

        if (is_string($chosen) && in_array($chosen, config('nvn.currencies'), true)) {
            return $chosen;
        }

        return self::detect($request);
    }

    /**
     * The currency this visitor's country implies, ignoring any choice.
     *
     * Naira for Nigeria, for an unreadable country, for a request that did not
     * come through Cloudflare, and when detection is switched off altogether.
     * Naira is the fallback in every one of those cases on purpose: it is the
     * currency the platform can actually collect and pay out in, so an unknown
     * visitor is quoted the one that works rather than the one that might not.
     */
    public static function detect(Request $request): string
    {
        if (! config('nvn.currency.by_location', true)) {
            return 'NGN';
        }

        $country = self::country($request);

        if ($country === null) {
            return 'NGN';
        }

        return $country === strtoupper((string) config('nvn.currency.home_country', 'NG'))
            ? 'NGN'
            : 'USD';
    }

    /**
     * The visitor's ISO-3166-1 alpha-2 country, or null if we cannot say.
     *
     * Cloudflare sends `XX` when it cannot place an address and `T1` for a Tor
     * exit node. Both mean "unknown", not "abroad", so both come back as null.
     */
    public static function country(Request $request): ?string
    {
        if (! $request->isFromTrustedProxy()) {
            return null;
        }

        $country = strtoupper(trim((string) $request->header('CF-IPCountry')));

        if ($country === '' || $country === 'XX' || $country === 'T1') {
            return null;
        }

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }

    /** Remember an explicit choice, which outranks the header from now on. */
    public static function remember(Request $request, string $currency): void
    {
        if (in_array($currency, config('nvn.currencies'), true)) {
            $request->session()?->put(self::SESSION_KEY, $currency);
        }
    }

    /**
     * Whether a request in this currency can actually be taken to checkout.
     *
     * Naira always. Dollars only once Paystack is known to accept them on this
     * account — see the `usd_checkout` note in config/nvn.php. This is asked at
     * the till rather than at the quote, so that shutting the valve mid-flow
     * cannot leave a client holding a dollar request nobody will take.
     */
    public static function canCharge(string $currency): bool
    {
        return $currency !== 'USD' || (bool) config('nvn.currency.usd_checkout', false);
    }

    /**
     * The currency a request should actually be created in.
     *
     * The quoted currency when we can collect it, naira when we cannot. This
     * is what keeps the interim state honest: while the dollar valve is shut a
     * visitor abroad still sees dollar prices, but the request itself — and
     * therefore the fee, the payment, the payout and the body's commission —
     * stays in the currency the platform can settle, with nothing silently
     * converted at a rate nobody set.
     */
    public static function chargeable(string $quoted): string
    {
        return self::canCharge($quoted) ? $quoted : 'NGN';
    }
}
