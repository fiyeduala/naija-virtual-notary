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

    /** For a sealed document: the upload it was made from. */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(RequestDocument::class, 'source_document_id');
    }

    /** For an upload: the sealed documents produced from it, newest last. */
    public function sealedVersions(): HasMany
    {
        return $this->hasMany(RequestDocument::class, 'source_document_id');
    }

    /** A short name for this upload, for tabs and lists. */
    public function label(): string
    {
        return $this->original_filename ?: ('Document #' . $this->id);
    }
}
