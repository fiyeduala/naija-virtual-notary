<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotaryProfile extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'specialties'            => 'array',
            'languages'              => 'array',
            'public_listing_enabled' => 'boolean',
            'is_system_native'       => 'boolean',
            'delegation_consent'     => 'boolean',
            'delegation_consent_at'  => 'datetime',
            'onboarding_fee_paid_at' => 'datetime',
            'approved_at'            => 'datetime',
            'commission_rate'        => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(NotaryCredential::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(NotaryAsset::class);
    }

    public function bankDetails(): HasOne
    {
        return $this->hasOne(NotaryBankDetail::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(NotaryService::class);
    }

    public function availability(): HasMany
    {
        return $this->hasMany(NotaryAvailability::class);
    }

    public function availabilityOverrides(): HasMany
    {
        return $this->hasMany(NotaryAvailabilityOverride::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(NotarizationRequest::class, 'notary_id');
    }

    // Scopes
    public function scopeListed($query)
    {
        return $query->where('verification_status', 'approved')
                     ->where('public_listing_enabled', true);
    }

    public function scopeSystemNative($query)
    {
        return $query->where('is_system_native', true);
    }

    /**
     * The three marks a notarial certificate cannot be produced without.
     *
     * `initials` is deliberately not among them: it is text used for page
     * initialling, not part of the seal itself, so its absence does not stop a
     * document being completed.
     */
    public const SEALING_ASSETS = ['signature', 'stamp', 'seal'];

    /**
     * Can this notary actually seal a document?
     *
     * The one definition of that question. It used to be asked three different
     * ways — "at least four assets" when a notary went live, "these three types
     * with files" when an admin listed them, and "any asset at all" when the
     * editor decided whose seal to offer. A notary could sit between two of
     * those answers: enough to be listed and booked, not enough to finish the
     * job, and past the check meant to catch exactly that.
     *
     * A row with no file_url does not count. After a host migration a row can
     * outlive the image it points at, and a seal that cannot be drawn is the
     * same as no seal.
     */
    public function canSeal(): bool
    {
        $assets = $this->relationLoaded('assets') ? $this->assets : $this->assets()->get();

        return $assets
            ->whereIn('type', self::SEALING_ASSETS)
            ->filter(fn (NotaryAsset $a) => filled($a->file_url))
            ->pluck('type')
            ->unique()
            ->count() === count(self::SEALING_ASSETS);
    }

    /** The amount the notary keeps, given a gross fee in minor units. */
    public function notaryShare(int $grossMinor): int
    {
        return (int) round($grossMinor * (100 - $this->commission_rate) / 100);
    }

    /** The amount the platform keeps. */
    public function platformShare(int $grossMinor): int
    {
        return $grossMinor - $this->notaryShare($grossMinor);
    }
}
