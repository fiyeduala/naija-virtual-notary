<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaryAsset extends Model
{
    protected $guarded = ['id'];

    public function notaryProfile(): BelongsTo
    {
        return $this->belongsTo(NotaryProfile::class);
    }
}
