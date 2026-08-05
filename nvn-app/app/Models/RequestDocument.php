<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequestDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_final_notarized' => 'boolean'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(NotarizationRequest::class, 'request_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(DocumentPlacement::class, 'document_id');
    }
}
