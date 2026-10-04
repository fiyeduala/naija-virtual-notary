<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Commission owed to a partner body, and the record of paying it.
 *
 * Deliberately shaped like Payout so the two read the same way — same
 * statuses, same settlement fields, same reference convention. One field is
 * missing and its absence is the design: there is no paystack_transfer_code,
 * because organization commission is settled by hand. The Paystack transfer
 * path is built around notary_bank_details.paystack_recipient_code, and a
 * second recipient pipeline is a separate piece of work rather than something
 * to half-build here.
 *
 * The ledger is payments.organization_payout_id. A fee counts towards what a
 * body is owed for exactly as long as it is unattached, and attaching it is
 * what makes it paid — see OrganizationPayoutService.
 */
class OrganizationPayout extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount'       => 'integer',
            'gross_amount' => 'integer',
            'period_start' => 'date',
            'period_end'   => 'date',
            'processed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /** The fees this payout settles. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'organization_payout_id');
    }

    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', ['pending', 'processing']);
    }

    public function isPending(): bool { return $this->status === 'pending'; }

    public function isPaid(): bool { return $this->status === 'paid'; }

    public function isFailed(): bool { return $this->status === 'failed'; }

    /** Settled by hand — which, for an organization, is every settlement. */
    public function isOffline(): bool { return $this->settlement_method !== null; }

    public function settlementLabel(): string
    {
        return \App\Support\SettlementMethod::label($this->settlement_method);
    }

    /** Still owing — somebody has to do something about it. */
    public function isSettleable(): bool
    {
        return in_array($this->status, ['pending', 'failed'], true) && $this->amount > 0;
    }

    /** What the platform kept out of the fees this payout settles. */
    public function platformAmount(): int
    {
        return max(0, $this->gross_amount - $this->amount);
    }

    public function displayAmount(): string
    {
        return NotarizationRequest::money($this->amount, $this->currency ?: 'NGN');
    }
}
