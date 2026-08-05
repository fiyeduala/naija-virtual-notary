<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaryBankDetail extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted', // encrypted at rest
            'verified_at'    => 'datetime',
            'name_matches'   => 'boolean',
        ];
    }

    public function notaryProfile(): BelongsTo
    {
        return $this->belongsTo(NotaryProfile::class);
    }

    /** The bank confirmed this account exists and told us who owns it. */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** Verified, and the account holder looks like the notary. */
    public function isPayable(): bool
    {
        return $this->isVerified() && $this->paystack_recipient_code !== null;
    }

    /** Safe to show on screen and in logs. */
    public function maskedAccountNumber(): string
    {
        $number = (string) $this->account_number;

        return $number === ''
            ? '—'
            : str_repeat('•', max(0, strlen($number) - 4)) . substr($number, -4);
    }
}
