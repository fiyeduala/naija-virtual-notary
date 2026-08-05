<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'completed_at' => 'datetime', 'amount' => 'integer'];
    }

    public function request(): BelongsTo { return $this->belongsTo(NotarizationRequest::class, 'request_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function payout(): BelongsTo { return $this->belongsTo(Payout::class); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }

    public function scopeSuccessful($query) { return $query->where('status', 'successful'); }

    /** Settled by hand rather than by Paystack. */
    public function isOffline(): bool { return $this->settlement_method !== null; }

    public function settlementLabel(): string
    {
        return \App\Support\SettlementMethod::label($this->settlement_method);
    }

    /**
     * Fees that count towards what a notary is owed: a cleared request fee, in
     * naira, not already settled by a payout.
     *
     * USD is excluded on purpose — Paystack transfers reach Nigerian bank
     * accounts in naira only, so a dollar fee cannot be paid out this way and
     * must not silently inflate a naira transfer.
     */
    public function scopePayable($query)
    {
        return $query->where('type', 'request_fee')
                     ->where('status', 'successful')
                     ->where('currency', 'NGN')
                     ->whereNull('payout_id');
    }
}
