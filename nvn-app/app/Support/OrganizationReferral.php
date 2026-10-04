<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Which partner body, if any, sent the person in front of us.
 *
 * Written as the sibling of App\Support\MetaAttribution and with the same
 * first-touch rule, because it answers the same shape of question: a referral
 * can only be read out of the browser that is actually here, and by the time
 * the money clears — in a webhook, or in an admin's hands days later — there
 * is no browser to read.
 *
 * Three places are consulted, in this order: the cookie, the session, then a
 * ?org=CODE on the URL. The cookie leads because it is what survives the sign-
 * in redirect; the query parameter is last because it is the thing a person
 * could paste over a referral they already have, and the first body to send
 * somebody is the one that earned them.
 *
 * Returns null for an ordinary visitor, which is almost everybody.
 */
class OrganizationReferral
{
    /** The cookie name. Short and unremarkable, like the pixel's own. */
    public const COOKIE = 'nvn_org';

    /** Mirrored in the session so a cookie-blocked browser still works. */
    public const SESSION_KEY = 'nvn_org_code';

    /**
     * The body this browser is carrying, if it is one we would honour.
     *
     * A paused, rejected, pending or deleted body resolves to null rather than
     * to itself: it must stop attributing new work the moment it is switched
     * off, and a stale cookie in somebody's browser cannot be recalled.
     */
    public static function capture(Request $request): ?Organization
    {
        foreach (self::candidateCodes($request) as $code) {
            $organization = self::resolve($code);

            if ($organization) {
                return $organization;
            }
        }

        return null;
    }

    /** Look a body up by its code or its slug, honouring only a live one. */
    public static function resolve(?string $code): ?Organization
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        return Organization::query()
            ->active()
            ->where(fn ($q) => $q->where('code', strtoupper($code))->orWhere('slug', strtolower($code)))
            ->first();
    }

    /**
     * Remember this body for as long as its arrangement says.
     *
     * Queued rather than attached to a response, so a controller that redirects
     * does not have to thread the cookie through every branch. Laravel flushes
     * the queue onto whatever response goes out.
     */
    public static function remember(Organization $organization, Request $request): void
    {
        $days = $organization->attributionDays();

        // Zero means lifetime. Cookie::forever() is five years, which is the
        // longest honest thing a browser cookie can claim.
        $days === 0
            ? Cookie::queue(Cookie::forever(self::COOKIE, $organization->code))
            : Cookie::queue(self::COOKIE, $organization->code, $days * 24 * 60);

        $request->session()->put(self::SESSION_KEY, $organization->code);
    }

    /** Stop attributing to anyone — used when a body turns out to be inactive. */
    public static function forget(Request $request): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
        $request->session()->forget(self::SESSION_KEY);
    }

    /**
     * The body that should be credited with a request this user is creating.
     *
     * The live cookie wins; failing that, the body that introduced them, for
     * as long as its attribution window says. Two people are excluded
     * outright:
     *
     *   - anyone who is not a client, so a notary or an admin who opens a
     *     partner's link out of curiosity never attributes their own work to
     *     it and never earns it a commission;
     *   - a body that is no longer active, by way of capture() and the window
     *     check below.
     */
    public static function forRequest(User $user, Request $request): ?Organization
    {
        if (! $user->isClient()) {
            return null;
        }

        if ($live = self::capture($request)) {
            return $live;
        }

        $user->loadMissing('referredByOrganization');
        $introduced = $user->referredByOrganization;

        if (! $introduced || ! $introduced->isActive()) {
            return null;
        }

        return $introduced->withinAttributionWindow($user->organization_referred_at)
            ? $introduced
            : null;
    }

    /**
     * Record the body that introduced a brand new account.
     *
     * First touch: a value already on the row is never replaced. Someone who
     * registered through an embassy's link and later clicks a law firm's one
     * is still the embassy's referral — otherwise whichever body happened to
     * send the last link would collect on work the first one introduced.
     */
    public static function stampUser(User $user, Request $request): void
    {
        if ($user->referred_by_organization_id !== null) {
            return;
        }

        $organization = self::capture($request);

        if (! $organization) {
            return;
        }

        $user->forceFill([
            'referred_by_organization_id' => $organization->id,
            'organization_referred_at'    => now(),
        ])->save();
    }

    /** @return array<int, string> */
    private static function candidateCodes(Request $request): array
    {
        return array_values(array_filter([
            $request->cookie(self::COOKIE),
            $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null,
            $request->query('org'),
        ], fn ($code) => is_string($code) && trim($code) !== ''));
    }
}
