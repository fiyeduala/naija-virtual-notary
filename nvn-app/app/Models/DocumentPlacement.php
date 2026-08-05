<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentPlacement extends Model
{
    protected $guarded = ['id'];

    public function document(): BelongsTo { return $this->belongsTo(RequestDocument::class, 'document_id'); }
    public function asset(): BelongsTo { return $this->belongsTo(NotaryAsset::class, 'asset_id'); }
    public function placedBy(): BelongsTo { return $this->belongsTo(User::class, 'placed_by'); }
}
