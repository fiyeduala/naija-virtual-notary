<?php

namespace App\Models;

use App\Support\RecruitmentPermission;
use App\Support\UploadGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A file somebody uploaded, always on the private disk.
 *
 * Nothing here is ever written under storage/app/public, so no symlink can
 * expose a CV, and there is no URL that serves one. Every read goes through a
 * controller that authorizes the requester against this particular
 * application — a path or an id is not permission to read a file.
 *
 * `is_sensitive` marks identity and guarantor paperwork. Those rows are
 * reachable only by a user holding sensitive.view, are left out of ordinary
 * exports entirely, and are never attached to an email. An ordinary reviewer
 * scoring an assessment has no route to them at all.
 *
 * `verification_status` is separate from the file existing, because a document
 * being uploaded is not evidence that anybody looked at it. Somebody has to
 * say they checked, and this records who and when.
 */
class ApplicationDocument extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public const KIND_CV         = 'cv';
    public const KIND_ASSESSMENT = 'assessment_attachment';
    public const KIND_IDENTITY   = 'identity';
    public const KIND_GUARANTOR  = 'guarantor';
    public const KIND_OFFER      = 'offer';
    public const KIND_OTHER      = 'other';

    public const KINDS = [
        self::KIND_CV         => 'CV',
        self::KIND_ASSESSMENT => 'Assessment attachment',
        self::KIND_IDENTITY   => 'Identity document',
        self::KIND_GUARANTOR  => 'Guarantor document',
        self::KIND_OFFER      => 'Offer document',
        self::KIND_OTHER      => 'Other',
    ];

    /** The kinds that are sensitive whatever anybody sets on the row. */
    public const ALWAYS_SENSITIVE = [self::KIND_IDENTITY, self::KIND_GUARANTOR];

    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_OK      = 'verified';
    public const VERIFICATION_FAILED  = 'not_verified';

    public const VERIFICATION_STATUSES = [
        self::VERIFICATION_PENDING => 'Not checked yet',
        self::VERIFICATION_OK      => 'Verified',
        self::VERIFICATION_FAILED  => 'Could not be verified',
    ];

    protected function casts(): array
    {
        return [
            'is_sensitive'          => 'boolean',
            'uploaded_by_candidate' => 'boolean',
            'scanned_at'            => 'datetime',
            'verified_at'           => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A kind that is sensitive by nature stays sensitive regardless of
        // what the caller passed, so a missed flag cannot quietly widen access
        // to somebody's passport.
        static::saving(function (self $document) {
            if (in_array($document->kind, self::ALWAYS_SENSITIVE, true)) {
                $document->is_sensitive = true;
            }
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** Everything an ordinary reviewer may see. */
    public function scopeOrdinary(Builder $query): Builder
    {
        return $query->where('is_sensitive', false);
    }

    /* ---- access --------------------------------------------------------- */

    /**
     * May this member of staff read this file?
     *
     * Two gates, not one: the application has to be theirs to review, AND a
     * sensitive file additionally needs sensitive.view. An HR administrator
     * sees identity paperwork; the reviewer marking the writing exercise does
     * not, and neither does a coordinator booking interviews.
     */
    public function readableBy(?User $user): bool
    {
        if (! $user || ! $this->application) {
            return false;
        }

        if (! RecruitmentPermission::canReview($user, $this->application)) {
            return false;
        }

        if ($this->is_sensitive) {
            return RecruitmentPermission::allows($user, RecruitmentPermission::VIEW_SENSITIVE);
        }

        return true;
    }

    /** May this candidate read it? Only their own, and never a private one. */
    public function readableByCandidate(?Candidate $candidate): bool
    {
        if (! $candidate || $this->candidate_id !== $candidate->id) {
            return false;
        }

        // An offer document is theirs to read; an internal note attached as a
        // file is not, and `other` is treated as internal by default.
        return in_array($this->kind, [
            self::KIND_CV,
            self::KIND_ASSESSMENT,
            self::KIND_IDENTITY,
            self::KIND_GUARANTOR,
            self::KIND_OFFER,
        ], true);
    }

    /* ---- storage -------------------------------------------------------- */

    public function exists(): bool
    {
        return Storage::disk($this->disk ?: 'private')->exists((string) $this->path);
    }

    /**
     * Delete the bytes as well as the row.
     *
     * Called by the retention service. A purge that leaves the files behind
     * has not deleted anything that matters.
     */
    public function purge(): void
    {
        $disk = Storage::disk($this->disk ?: 'private');

        if ($this->path && $disk->exists($this->path)) {
            $disk->delete($this->path);
        }

        $this->forceDelete();
    }

    public function scanLabel(): string
    {
        return UploadGuard::scanLabel($this->scan_status);
    }

    public function verificationLabel(): string
    {
        return self::VERIFICATION_STATUSES[$this->verification_status] ?? 'Not checked yet';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Document';
    }

    public function sizeForHumans(): string
    {
        $bytes = (int) $this->size;

        return $bytes >= 1048576
            ? round($bytes / 1048576, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
