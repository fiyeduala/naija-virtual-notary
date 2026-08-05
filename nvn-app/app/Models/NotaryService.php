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
