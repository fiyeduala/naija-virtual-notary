<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaryAvailabilityOverride extends Model
{
    protected $table = 'notary_availability_overrides';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'available' => 'boolean'];
    }

    public function notaryProfile(): BelongsTo
    {
        return $this->belongsTo(NotaryProfile::class);
    }
}
