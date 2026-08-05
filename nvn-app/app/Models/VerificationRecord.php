<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function session(): BelongsTo { return $this->belongsTo(Session::class); }
    public function notary(): BelongsTo { return $this->belongsTo(User::class, 'notary_id'); }
    public function client(): BelongsTo { return $this->belongsTo(User::class, 'client_id'); }
}
