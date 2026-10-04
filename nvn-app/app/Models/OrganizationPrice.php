<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One category priced differently for one body.
 *
 * An override on top of Organization::$default_price_ngn, for the case where a
 * body negotiated a rate per kind of document rather than one rate for
 * everything. Absent for most organizations, which is why the default exists.
 *
 * Keyed on the service row rather than a category name, which is only safe
 * because organization work is always notarized by the platform's own profile —
 * so there is exactly one service list to key against.
 */
class OrganizationPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_ngn' => 'integer',
            'price_usd' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(NotaryService::class, 'notary_service_id');
    }

    /** Minor units for the chosen currency, or null if this override is blank. */
    public function priceFor(string $currency): ?int
    {
        $minor = $currency === 'USD' ? $this->price_usd : $this->price_ngn;

        return $minor === null ? null : (int) $minor;
    }
}
