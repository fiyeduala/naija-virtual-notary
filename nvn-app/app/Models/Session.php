<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Session extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at'   => 'datetime',
            'actual_start_at'    => 'datetime',
            'actual_end_at'      => 'datetime',
            'identity_verified'  => 'boolean',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(NotarizationRequest::class, 'request_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SessionParticipant::class);
    }

    public function verificationRecord(): HasOne
    {
        return $this->hasOne(VerificationRecord::class);
    }
}
