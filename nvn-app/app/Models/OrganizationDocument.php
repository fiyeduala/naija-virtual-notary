<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paperwork a partner body sent in support of its application.
 *
 * Shaped like NotaryCredential, and a table rather than two columns for the
 * same reason: what a body can produce varies — a certificate of registration
 * from a company, a request on letterhead from a government office — and the
 * office often asks for one more thing after a telephone call.
 */
class OrganizationDocument extends Model
{
    protected $guarded = ['id'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** What the admin panel should call this. */
    public function typeLabel(): string
    {
        return ucfirst(str_replace('_', ' ', (string) $this->document_type));
    }
}
