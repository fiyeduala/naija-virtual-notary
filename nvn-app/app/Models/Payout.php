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
     * Sendable only with a verified account that has a transfer recipient, and
     * only when the platform is switched on for automatic transfers at all.
     */
    public function isSendable(): bool
    {
        return \App\Support\Settings::paystackTransfersEnabled()
            && in_array($this->status, ['pending', 'failed'], true)
            && $this->amount > 0
            && $this->notaryProfile?->bankDetails?->isPayable() === true;
    }

    /** Gross fees settled here — the notary's share plus the platform's. */
    public function grossAmount(): int
    {
        return $this->amount + $this->commission_amount;
    }
}
