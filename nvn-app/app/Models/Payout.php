<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount'            => 'integer',
            'commission_amount' => 'integer',
            'settled_amount'    => 'integer',
            'period_start'      => 'date',
            'period_end'        => 'date',
            'processed_at'      => 'datetime',
        ];
    }

    public function notaryProfile(): BelongsTo { return $this->belongsTo(NotaryProfile::class); }

    public function initiatedBy(): BelongsTo { return $this->belongsTo(User::class, 'initiated_by'); }

    /** The fees this payout settles. Detached again if the transfer fails. */
    public function payments(): HasMany { return $this->hasMany(Payment::class); }

    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', ['pending', 'processing']);
    }

    /** Not yet sent to Paystack — safe to cancel or regenerate. */
    public function isPending(): bool { return $this->status === 'pending'; }

    /** Handed to Paystack, waiting on the transfer webhook. */
    public function isProcessing(): bool { return $this->status === 'processing'; }

    public function isPaid(): bool { return $this->status === 'paid'; }

    public function isFailed(): bool { return $this->status === 'failed'; }

    /** Settled by hand rather than by a Paystack transfer. */
    public function isOffline(): bool { return $this->settlement_method !== null; }

    public function settlementLabel(): string
    {
        return \App\Support\SettlementMethod::label($this->settlement_method);
    }

    /** Still owing — the admin has to do something about it, either way. */
    public function isSettleable(): bool
    {
        return in_array($this->status, ['pending', 'processing', 'failed'], true) && $this->amount > 0;
    }

    /**
     * Denominated in something a Paystack transfer cannot send.
     *
     * A dollar payout exists because a client abroad paid dollars; the notary
     * has a Nigerian account and can only receive naira. The earning is real
     * and recorded in the currency it was earned in — settling it is a
     * by-hand job, and the naira that actually left the bank is recorded
     * separately. See PayoutService::generateForeign().
     */
    public function isForeign(): bool
    {
        return $this->currency !== 'NGN';
    }

    /**
     * Sendable only with a verified account that has a transfer recipient, and
     * only when the platform is switched on for automatic transfers at all.
     *
     * Naira only, and that clause is load-bearing rather than tidy:
     * initiateTransfer() takes a bare integer of minor units and Paystack
     * sends naira, so handing it a payout of 5,000 cents would quietly wire
     * ₦50 against a $50 debt. Nothing downstream would notice.
     */
    public function isSendable(): bool
    {
        return \App\Support\Settings::paystackTransfersEnabled()
            && ! $this->isForeign()
            && in_array($this->status, ['pending', 'failed'], true)
            && $this->amount > 0
            && $this->notaryProfile?->bankDetails?->isPayable() === true;
    }

    /** The rate this settlement was actually done at, or null if it is moot. */
    public function impliedRate(): ?float
    {
        if (! $this->settled_amount || ! $this->amount) {
            return null;
        }

        // Both sides are minor units, so the factors of 100 cancel and this is
        // naira-per-dollar directly. Derived rather than stored, so it can
        // never disagree with the two figures it comes from.
        return $this->settled_amount / $this->amount;
    }

    /** Gross fees settled here — the notary's share plus the platform's. */
    public function grossAmount(): int
    {
        return $this->amount + $this->commission_amount;
    }
}
