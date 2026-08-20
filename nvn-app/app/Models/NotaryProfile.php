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
            'membership_expires_at'  => 'datetime',
            'membership_reminded_at' => 'datetime',
            'listing_requested_at'   => 'datetime',
            'listed_at'              => 'datetime',
            'approved_at'            => 'datetime',
            'commission_rate'        => 'integer',
        ];
    }

    /** How long one partner fee buys. */
    public const MEMBERSHIP_MONTHS = 12;

    /** How far ahead a partner starts being told their membership is ending. */
    public const RENEWAL_NOTICE_DAYS = 30;

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

    /**
     * Notaries a client may see and book.
     *
     * Membership is part of this now. A partner whose year has run out is not
     * removed, disabled or told they are gone — they simply stop appearing in
     * the marketplace until they renew, which is what the yearly fee buys. Work
     * already booked is untouched: a lapse must never strand a client who has
     * paid, so nothing about an in-flight request looks at this scope.
     */
    public function scopeListed($query)
    {
        return $query->where('verification_status', 'approved')
                     ->where('public_listing_enabled', true)
                     ->where(fn ($q) => $q
                         ->where('is_system_native', true)
                         ->orWhere('membership_expires_at', '>', now()));
    }

    public function scopeSystemNative($query)
    {
        return $query->where('is_system_native', true);
    }

    /** Notaries who have asked to be listed and are still waiting on an answer. */
    public function scopeAwaitingListingReview($query)
    {
        return $query->whereNotNull('listing_requested_at')
                     ->where('public_listing_enabled', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    |
    | Being in the marketplace is granted by an admin, never taken. Completing a
    | profile only earns the right to ask. See the migration that added these
    | columns for the incident behind that rule.
    */

    /** Has this notary asked to be listed and not yet been answered? */
    public function isAwaitingListingReview(): bool
    {
        return $this->listing_requested_at !== null && ! $this->public_listing_enabled;
    }

    /**
     * Everything that must be true before a listing can even be asked for.
     *
     * Returns the reasons it cannot, in the order a notary would fix them, so
     * the caller can say which one is in the way rather than "profile
     * incomplete". Empty means ready to ask — not ready to appear.
     *
     * @return list<string>
     */
    public function listingBlockers(): array
    {
        $blockers = [];

        if ($this->verification_status !== 'approved') {
            $blockers[] = 'your application is still being reviewed';
        }

        // Signature, stamp and seal, each with a file behind it. Counting rows
        // was not the same question: four rows can still be missing the seal.
        if (! $this->canSeal()) {
            $missing = $this->missingSealingAssets();

            $blockers[] = 'we do not have your ' . (count($missing) > 1
                ? implode(', ', array_slice($missing, 0, -1)) . ' and ' . end($missing)
                : ($missing[0] ?? 'sealing marks'));
        }

        // A bank code, not merely a row: an account saved before the payout
        // rework has a typed bank name that nothing can be transferred to.
        // Verification itself is not required — it can fail for reasons that
        // are not the notary's fault, and an admin can re-run it.
        $bank = $this->relationLoaded('bankDetails') ? $this->bankDetails : $this->bankDetails()->first();

        if ($bank?->bank_code === null) {
            $blockers[] = 'your payout account is not set up';
        }

        $services = $this->relationLoaded('services')
            ? $this->services->count()
            : ($this->services_count ?? $this->services()->count());

        if ($services < 1) {
            $blockers[] = 'you have not priced a single service';
        }

        return $blockers;
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
        return $this->missingSealingAssets() === [];
    }

    /**
     * Which of the three marks are absent, named individually.
     *
     * `canSeal()` answers yes or no, which is the right answer for a gate and
     * the wrong one for an email: it cannot tell you whether to ask somebody
     * for one file or for three.
     *
     * @return list<string>
     */
    public function missingSealingAssets(): array
    {
        $assets = $this->relationLoaded('assets') ? $this->assets : $this->assets()->get();

        $held = $assets
            ->filter(fn (NotaryAsset $a) => filled($a->file_url))
            ->pluck('type')
            ->all();

        return array_values(array_filter(
            self::SEALING_ASSETS,
            fn (string $type) => ! in_array($type, $held, true)
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Membership
    |--------------------------------------------------------------------------
    |
    | The partner fee buys a year on the platform, not a permanent place on it.
    | onboarding_fee_paid_at still records the day someone joined and is never
    | rewritten; membership_expires_at is what every live check reads.
    */

    /** Is this partner paid up? */
    public function membershipActive(): bool
    {
        // The platform's own notary has no one to pay and no one to pay it.
        // Nothing about the system-native profile should ever be able to lapse.
        if ($this->is_system_native) {
            return true;
        }

        return $this->membership_expires_at?->isFuture() ?? false;
    }

    /** Has a membership existed and run out, as opposed to never having started? */
    public function membershipLapsed(): bool
    {
        return ! $this->is_system_native
            && $this->membership_expires_at !== null
            && $this->membership_expires_at->isPast();
    }

    /** Whole days until the membership ends. Negative once it has. */
    public function membershipDaysLeft(): ?int
    {
        if ($this->is_system_native || $this->membership_expires_at === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->membership_expires_at->startOfDay(), false);
    }

    /** Close enough to the end that the partner should be hearing about it. */
    public function membershipEndingSoon(): bool
    {
        $days = $this->membershipDaysLeft();

        return $days !== null && $days <= self::RENEWAL_NOTICE_DAYS;
    }

    /**
     * Add another year of membership.
     *
     * Measured from whichever is later: today, or the expiry they already hold.
     * A partner who renews a fortnight early keeps that fortnight — losing time
     * by paying early would teach everyone to wait until the day they lapse.
     */
    public function extendMembership(): void
    {
        $from = $this->membershipActive() && $this->membership_expires_at
            ? $this->membership_expires_at
            : now();

        $this->update([
            'membership_expires_at'  => $from->copy()->addMonths(self::MEMBERSHIP_MONTHS),
            // A fresh year means the last lapse warning is spent.
            'membership_reminded_at' => null,
        ]);
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
