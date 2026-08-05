<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaryAvailability extends Model
{
    protected $table = 'notary_availability';
    protected $guarded = ['id'];

    public function notaryProfile(): BelongsTo
    {
        return $this->belongsTo(NotaryProfile::class);
    }
}
