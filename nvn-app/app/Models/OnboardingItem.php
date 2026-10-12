<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One thing still to be done before somebody starts.
 *
 * `submitted` and `verified` are different statuses on purpose. A candidate
 * uploading a document has submitted it; somebody in the office still has to
 * look at it and say so. Treating an upload as a check is how an unverified
 * document ends up recorded as verified by nobody in particular.
 *
 * `is_sensitive` items are visible only to a user holding sensitive.view and
 * are left out of ordinary exports.
 */
class OnboardingItem extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public const PENDING      = 'pending';
    public const SUBMITTED    = 'submitted';
    public const VERIFIED     = 'verified';
    public const NOT_VERIFIED = 'not_verified';
    public const WAIVED       = 'waived';

    public const STATUSES = [
        self::PENDING      => 'Waiting on the candidate',
        self::SUBMITTED    => 'Submitted, not yet checked',
        self::VERIFIED     => 'Verified',
        self::NOT_VERIFIED => 'Could not be verified',
        self::WAIVED       => 'Not required',
    ];

    public const KIND_DOCUMENT     = 'document';
    public const KIND_REFERENCE    = 'reference';
    public const KIND_CONFIRMATION = 'confirmation';

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'is_required'  => 'boolean',
            'verified_at'  => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ApplicationDocument::class, 'document_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeOrdinary(Builder $query): Builder
    {
        return $query->where('is_sensitive', false);
    }

    /** Outstanding, for the "can we mark them hired" question. */
    public function isOutstanding(): bool
    {
        return $this->is_required
            && ! in_array($this->status, [self::VERIFIED, self::WAIVED], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? 'Waiting on the candidate';
    }
}
