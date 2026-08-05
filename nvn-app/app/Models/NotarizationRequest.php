<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotarizationRequest extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'              => RequestStatus::class,
            'intake_data'         => 'array',
            'delivery_address'    => 'array',
            'hard_copy_requested' => 'boolean',
            'was_fallback'        => 'boolean',
            'notary_notified_at'  => 'datetime',
            'fallback_due_at'     => 'datetime',
            'fallback_alerted_at' => 'datetime',
            'submitted_at'        => 'datetime',
            'paid_at'             => 'datetime',
            'accepted_at'         => 'datetime',
            'completed_at'        => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            $request->reference ??= static::generateReference();
        });
    }

    public static function generateReference(): string
    {
        do {
            $ref = 'NVN-' . date('Y') . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (static::where('reference', $ref)->exists());

        return $ref;
    }

    // Relationships
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function notary(): BelongsTo
    {
        return $this->belongsTo(NotaryProfile::class, 'notary_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(NotaryService::class, 'service_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RequestDocument::class, 'request_id');
    }

    public function finalDocument(): HasOne
    {
        return $this->hasOne(RequestDocument::class, 'request_id')
                    ->where('is_final_notarized', true);
    }

    public function session(): HasOne
    {
        return $this->hasOne(Session::class, 'request_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'request_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'request_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * Scope: paid requests the assigned notary has not answered inside the
     * response window, and that nobody has been alerted about yet.
     *
     * This used to feed an automatic reassignment. It no longer does — the
     * window is an SLA clock, not a transfer of the job. ProcessFallbacks
     * uses this to raise the request on the admin desk exactly once.
     */
    public function scopeOverdueForResponse($query)
    {
        return $query->where('status', RequestStatus::Paid->value)
                     ->whereNotNull('fallback_due_at')
                     ->where('fallback_due_at', '<=', now())
                     ->whereNull('fallback_alerted_at');
    }

    /**
     * Scope: everything still in flight, anywhere on the platform.
     *
     * The admin may notarize on any notary's behalf, so their desk is not
     * limited to what has been handed to them — see scopeOnDeskOf() for the
     * narrower "assigned to me" set.
     */
    public function scopeInFlight($query)
    {
        return $query->whereIn('status', array_map(
            fn (RequestStatus $s) => $s->value,
            RequestStatus::active(),
        ));
    }

    /**
     * Scope: everything sitting on one person's notary desk.
     *
     * Two ways a request lands there. Normally it is assigned to their notary
     * profile. But when a partner declines or times out, RequestFulfillmentService
     * leaves notary_id pointing at the original notary — so the client's invoice
     * and price stay intact — and records the person actually doing the work in
     * handled_by. Filtering on notary_id alone would hide every fallback job from
     * the admin who has to notarize it.
     */
    public function scopeOnDeskOf($query, User $user)
    {
        $profileId = $user->notaryProfile?->id;

        return $query->where(function ($q) use ($profileId, $user) {
            if ($profileId) {
                $q->where('notary_id', $profileId);
            }

            $q->orWhere('handled_by', $user->id);
        });
    }

    /**
     * The notary profile whose signature, stamp and seal go on the document.
     *
     * Always the notary the client selected. When the platform notarizes on a
     * partner's behalf it does so under that partner's commission, name and
     * seal — the client picked them, paid their price, and the certificate has
     * to say so. The only time the platform's own seal is used is when the
     * client selected the platform's own notary in the first place, which this
     * returns naturally because notary_id points at the system-native profile.
     */
    public function sealingProfile(): ?NotaryProfile
    {
        return $this->notary;
    }

    /** True once the platform is doing the work instead of the assigned notary. */
    public function isCoveredByPlatform(): bool
    {
        return $this->handled_by !== null
            && $this->notary !== null
            && ! $this->notary->is_system_native;
    }

    /** Past the response window and still unanswered. */
    public function isOverdue(): bool
    {
        return $this->status === RequestStatus::Paid
            && $this->fallback_due_at !== null
            && $this->fallback_due_at->isPast();
    }

    /** Has the sealed document been produced? */
    public function isNotarized(): bool
    {
        return $this->completed_at !== null;
    }
}
