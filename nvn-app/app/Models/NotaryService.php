<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaryService extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active'    => 'boolean',
            'price_ngn' => 'integer', // kobo
            'price_usd' => 'integer', // cents
        ];
    }

    public function notaryProfile(): BelongsTo
    {
        return $this->belongsTo(NotaryProfile::class);
    }

    /** Price in minor units for the chosen currency. */
    public function priceFor(string $currency): int
    {
        return $currency === 'USD' ? $this->price_usd : $this->price_ngn;
    }

    /**
     * Whether a dollar price looks like the naira one with the zeros dropped.
     *
     * ₦20,000 and $20 is the tell: a price written by transliterating the
     * naira figure rather than by pricing the service. At roughly ₦1,550 to
     * the dollar, $20 is about ₦31,000 — half again as much as the naira
     * price — so the two lists quietly disagree about what the same job costs,
     * and which one a client sees comes down to where they happen to be.
     *
     * It may of course be deliberate: charging a diaspora client more is a
     * decision an office is entitled to make. So this warns and never blocks.
     *
     * Both figures are in MAJOR units here (naira and dollars), because this
     * is asked by an admin form holding what a human just typed, not by the
     * money code, which works in kobo and cents throughout.
     */
    public static function usdPriceWarning(?float $priceNgn, ?float $priceUsd): ?string
    {
        if (! $priceNgn || ! $priceUsd) {
            return null;
        }

        $rate = (int) config('nvn.currency.reference_rate', 1550);

        if ($rate < 1) {
            return null;
        }

        // The exact 1:1000 shape, which is what a dropped-zeros price looks like.
        if (abs($priceNgn / 1000 - $priceUsd) < 0.01) {
            return 'Looks like the naira price with the zeros dropped. At ~₦'
                . number_format($rate) . '/$ this is ₦' . number_format($priceUsd * $rate)
                . ', not ₦' . number_format($priceNgn) . '.';
        }

        $impliedRate = ($priceNgn / $priceUsd);

        // Anything beyond a third either side of the reference rate is worth a
        // second look. A band rather than a figure, because the real rate
        // moves and a deliberate premium is legitimate.
        if ($impliedRate > $rate * 1.34 || $impliedRate < $rate * 0.66) {
            return 'This implies ₦' . number_format($impliedRate) . '/$, against a reference of ₦'
                . number_format($rate) . '/$. Deliberate premium, or a typo?';
        }

        return null;
    }

    /**
     * Is there a dollar price we could actually quote a client abroad?
     *
     * Asked before a foreign visitor is shown dollars at all: quoting $0.00
     * because nobody filled the column in is worse than quoting naira.
     */
    public function hasUsableUsdPrice(): bool
    {
        return (int) $this->price_usd > 0;
    }

    /** Human-readable price, e.g. "₦30,000.00" or "$50.00". */
    public function displayPrice(string $currency): string
    {
        $minor = $this->priceFor($currency);
        $major = $minor / 100;

        return $currency === 'USD'
            ? '$' . number_format($major, 2)
            : '₦' . number_format($major, 2);
    }
}
