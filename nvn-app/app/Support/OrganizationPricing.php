<?php

namespace App\Support;

use App\Models\NotarizationRequest;
use App\Models\NotaryService;
use App\Models\Organization;

/**
 * What a partner body's referrals are charged.
 *
 * The one definition. A negotiated rate is a figure, not a discount: ₦25,000
 * to the public and ₦250,000 to a government body is not expressible as a
 * percentage of anything, and trying to would make the quote depend on a
 * public price that may move for unrelated reasons.
 *
 * The price reaches the fee through notarization_requests.unit_fee_minor,
 * which already means "a frozen unit price wins over the service" — so
 * NotarizationRequest::feeMinor() needs no new branch, and there remains
 * exactly one definition of what a request costs.
 */
class OrganizationPricing
{
    /**
     * This body's unit price for one category, in minor units.
     *
     * A per-category override first, then the body's default. Falls back to
     * the public price only if the body has neither — which an approved body
     * cannot, because Organization::missingBeforeApproval() refuses to let one
     * go live without a default naira price. The fallback is there so that a
     * misconfigured body quotes the ordinary price rather than ₦0.00.
     */
    public function unitPriceMinor(Organization $organization, NotaryService $service, string $currency = 'NGN'): int
    {
        $override = $organization->prices
            ->firstWhere('notary_service_id', $service->id)
            ?->priceFor($currency);

        if ($override !== null) {
            return $override;
        }

        $default = $currency === 'USD'
            ? $organization->default_price_usd
            : $organization->default_price_ngn;

        return $default !== null ? (int) $default : $service->priceFor($currency);
    }

    /** The same figure, formatted for a quote. */
    public function displayUnitPrice(Organization $organization, NotaryService $service, string $currency = 'NGN'): string
    {
        return NotarizationRequest::money(
            $this->unitPriceMinor($organization, $service, $currency),
            $currency,
        );
    }

    /**
     * What each of these categories would cost per document on this request.
     *
     * Minor units, keyed by service id. Every screen that lists categories
     * against prices asks this, so that a body's referral is never quoted a
     * public figure by a screen that simply forgot to ask — and so the three
     * numbers such a screen shows (the price, the total for the documents and
     * the difference still owed) all come from one place.
     *
     * Ordinary work gets the public price, so a call site needs no branch.
     *
     * @param  iterable<NotaryService>  $services
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function quotesFor(NotarizationRequest $request, iterable $services): \Illuminate\Support\Collection
    {
        $currency = $request->currency ?: 'NGN';
        $organization = $request->organization;
        $organization?->loadMissing('prices');

        return collect($services)->mapWithKeys(fn (NotaryService $service) => [
            $service->id => $organization
                ? $this->unitPriceMinor($organization, $service, $currency)
                : (int) $service->priceFor($currency),
        ]);
    }

    /**
     * Freeze this body's price onto a request, now that a category is known.
     *
     * Called at the two moments a category is chosen — selection, and a
     * correction after the desk queries it. Not at intake: isPriced() reads
     * `service_id !== null || unit_fee_minor !== null`, so writing a price
     * before a category exists would make a request with nothing chosen claim
     * to be priced, and it would then drop out of the unpaid-follow-up list
     * that exists to chase exactly that client.
     *
     * A no-op for ordinary work, so call sites do not need to branch.
     */
    public function freezeOnto(NotarizationRequest $request, NotaryService $service): void
    {
        if (! $request->fromOrganization()) {
            return;
        }

        $organization = $request->organization;

        if (! $organization) {
            return;
        }

        $organization->loadMissing('prices');

        $request->update([
            'unit_fee_minor' => $this->unitPriceMinor(
                $organization,
                $service,
                $request->currency ?: 'NGN',
            ),
        ]);
    }
}
