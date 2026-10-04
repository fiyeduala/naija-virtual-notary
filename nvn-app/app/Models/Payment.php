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
    public function organizationPayout(): BelongsTo { return $this->belongsTo(OrganizationPayout::class); }
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

    /**
     * Fees that count towards what a partner BODY is owed.
     *
     * The same three conditions as scopePayable(), against a different ledger
     * column. Two independent columns rather than one shared "settled" flag:
     * a notary payout and an organization payout are separate questions about
     * the same money, and neither run may see the other's claim or a fee would
     * be paid once and counted twice.
     *
     * USD is excluded for the same reason as above — commission is settled
     * into a Nigerian bank account, in naira.
     */
    public function scopeOrganizationPayable($query)
    {
        return $query->where('type', 'request_fee')
                     ->where('status', 'successful')
                     ->where('currency', 'NGN')
                     ->whereNull('organization_payout_id');
    }
}
