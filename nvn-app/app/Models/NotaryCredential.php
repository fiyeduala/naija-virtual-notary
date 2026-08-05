<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaryCredential extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['reviewed_at' => 'datetime']; }
    public function notaryProfile(): BelongsTo { return $this->belongsTo(NotaryProfile::class); }
}
