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

    /**
     * Uploads that are notarial work rather than evidence.
     *
     * The identification and the client's captured signature live on the same
     * table and are neither sealed nor billed.
     */
    public const NOTARIZABLE_TYPES = ['document', 'additional'];

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
            'payment_followed_up_at' => 'datetime',
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

    /**
     * The uploads that get notarized, primary first.
     *
     * The client's ID and their signature capture ride on the same table but
     * are evidence, not work: they are never sealed and never billed. This
     * relation is the definition of "what was actually asked for", and both
     * the price and the editor are driven from it so the two cannot disagree.
     */
    public function notarizableDocuments(): HasMany
    {
        return $this->hasMany(RequestDocument::class, 'request_id')
                    ->whereIn('file_type', self::NOTARIZABLE_TYPES)
                    // 'document' before 'additional' — the primary upload is the
                    // one the client thinks of as "the" document, so it leads
                    // the editor's tabs and seals first.
                    ->orderByRaw("CASE WHEN file_type = 'document' THEN 0 ELSE 1 END")
                    ->orderBy('id');
    }

    /** Every sealed output — one per notarized upload. */
    public function finalDocuments(): HasMany
    {
        return $this->hasMany(RequestDocument::class, 'request_id')
                    ->where('is_final_notarized', true)
                    ->orderBy('id');
    }

    /**
     * The first sealed document.
     *
     * Kept because a request had exactly one of these before additional
     * documents were billed and sealed, and several screens still ask the
     * yes/no question "is there a finished document yet?". Anything that
     * offers the finished work to somebody should use finalDocuments().
     */
    public function finalDocument(): HasOne
    {
        return $this->hasOne(RequestDocument::class, 'request_id')
                    ->where('is_final_notarized', true)
                    ->oldestOfMany('id');
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

    /*
    |--------------------------------------------------------------------------
    | What this request costs
    |--------------------------------------------------------------------------
    |
    | Every document on a request is a separate notarial act — a separate seal,
    | a separate signature, a separate finished PDF — so each is charged at the
    | notary's full price for the service. The fee is defined here and nowhere
    | else: the checkout, the review screen the client agrees to, and an admin
    | recording a bank transfer all read it from this method, because a total
    | computed twice is a total that will eventually disagree with itself.
    */

    /** How many documents are being notarized, and therefore billed. */
    public function billableDocumentCount(): int
    {
        $count = $this->relationLoaded('notarizableDocuments')
            ? $this->notarizableDocuments->count()
            : $this->notarizableDocuments()->count();

        // Intake will not accept a request without a primary document, so this
        // floor should never bind. It exists so that a request which somehow
        // arrives here with no documents is charged the old single-document
        // price rather than being handed over for nothing.
        return max(1, $count);
    }

    /** The full fee in minor units (kobo / cents). */
    public function feeMinor(?string $currency = null): int
    {
        $currency ??= $this->currency ?: 'NGN';

        $unitPrice = $this->service?->priceFor($currency) ?? 0;

        return $unitPrice * $this->billableDocumentCount();
    }

    /** The full fee, formatted — e.g. "₦45,000.00". */
    public function displayFee(?string $currency = null): string
    {
        $currency ??= $this->currency ?: 'NGN';

        return static::money($this->feeMinor($currency), $currency);
    }

    /**
     * What has actually cleared against this request, in minor units.
     *
     * A sum rather than a single row on purpose. A client may pay in parts —
     * they paid for one document, then sent the rest by transfer — and the
     * fee is settled when the parts add up, not when the first one arrives.
     */
    public function amountPaidMinor(): int
    {
        return (int) $this->payments()
            ->where('type', 'request_fee')
            ->where('status', 'successful')
            ->sum('amount');
    }

    /** Still owed, in minor units. Never negative: an overpayment is a refund question. */
    public function balanceMinor(): int
    {
        return max(0, $this->feeMinor() - $this->amountPaidMinor());
    }

    /** Has the whole fee arrived, however many payments it took? */
    public function isFullyPaid(): bool
    {
        return $this->balanceMinor() === 0;
    }

    /**
     * Waiting on money that has never arrived.
     *
     * Narrower than "not fully paid": a request in flight can still owe a
     * balance after a part payment, and that client is mid-job rather than
     * stalled. This is the one who filled the form in and stopped.
     */
    public function awaitingPayment(): bool
    {
        return in_array($this->status, [RequestStatus::Draft, RequestStatus::Submitted], true)
            && ! $this->isFullyPaid();
    }

    /**
     * The database-side half of awaitingPayment(), for listing and filtering.
     *
     * Named differently on purpose: a scope whose name matches a real method on
     * the model can only ever be reached through a builder, because the static
     * form calls the instance method instead and fatals. It is also the wider
     * of the two — status alone, since the paid total is a sum of rows rather
     * than a column — so callers that care re-check awaitingPayment().
     */
    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', [RequestStatus::Draft->value, RequestStatus::Submitted->value]);
    }

    /** The outstanding balance, formatted. */
    public function displayBalance(): string
    {
        return static::money($this->balanceMinor(), $this->currency ?: 'NGN');
    }

    /** Minor units to a readable figure. One place, so the symbols cannot drift. */
    public static function money(int $minor, string $currency = 'NGN'): string
    {
        return ($currency === 'USD' ? '$' : '₦') . number_format($minor / 100, 2);
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
