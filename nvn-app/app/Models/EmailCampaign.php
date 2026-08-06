<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An email the admin sent from the panel. See EmailCampaignService for how the
 * recipient list is built and how sending is paced.
 */
class EmailCampaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'queued_at'    => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Everyone this audience covers, before the opt-out is applied.
     *
     * Suspended and unverified accounts are deliberately included for 'all' —
     * a service announcement is often exactly what a suspended user needs. The
     * compose screen shows the count so nobody sends blind.
     */
    public static function audienceQuery(string $audience): Builder
    {
        $query = User::query()->whereNotNull('email');

        return match ($audience) {
            'clients'  => $query->where('role', 'client'),
            'notaries' => $query->where('role', 'notary'),
            // Admins are people too, and an "everyone" announcement that
            // silently skipped the staff would be a surprise.
            default    => $query,
        };
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['sent', 'cancelled'], true);
    }

    /** A send that has been queued but has not finished working through. */
    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'sending'], true);
    }

    public function pendingCount(): int
    {
        return $this->recipients()->where('status', 'pending')->count();
    }
}
