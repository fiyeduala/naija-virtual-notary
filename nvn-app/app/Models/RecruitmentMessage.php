<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued candidate email. The transactional outbox.
 *
 * Nothing in this module calls Mail directly. A row is written inside the same
 * transaction as the change that justifies it, and a worker sends it
 * afterwards. That ordering buys two things that mail-inside-a-transaction
 * cannot:
 *
 *   - A transaction that rolls back takes its email with it, so nobody is
 *     invited to an assessment that was never created.
 *   - A committed change cannot lose its email to a crash between the commit
 *     and the send, because the instruction to send is part of the commit.
 *
 * `event_key` is the idempotency guarantee. One row per
 * (type, application, version), enforced by a unique index — so a retry, a
 * double-clicked button or two reviewers acting at once cannot produce a
 * second acknowledgment.
 *
 * Before a message goes out, the sender re-reads the world: the stage, the
 * vacancy, whether the candidate withdrew, whether the deadline has passed,
 * whether the thing it announces has already happened. A delayed invitation
 * that was true when it was queued may be wrong by the time it is due, and a
 * wrong email to a candidate is not recoverable.
 *
 * `delivery_status` is NOT the same as `status`. A provider accepting a
 * message says the provider has it; it does not say a person received it. This
 * column stays null unless a provider event actually confirms delivery, and
 * this installation has no such feed, so the screen says "sent" rather than
 * claiming more than it knows.
 */
class RecruitmentMessage extends Model
{
    protected $guarded = ['id'];

    public const PENDING    = 'pending';
    public const SENT       = 'sent';
    public const FAILED     = 'failed';
    public const CANCELLED  = 'cancelled';
    public const SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::PENDING    => 'Waiting to go out',
        self::SENT       => 'Handed to the mail server',
        self::FAILED     => 'Failed',
        self::CANCELLED  => 'Called off',
        self::SUPERSEDED => 'Replaced by a newer message',
    ];

    /** How many times the worker will try before leaving it for a human. */
    public const MAX_ATTEMPTS = 3;

    protected function casts(): array
    {
        return [
            'payload'               => 'array',
            'available_at'          => 'datetime',
            'dispatched_at'         => 'datetime',
            'sent_at'               => 'datetime',
            'failed_at'             => 'datetime',
            'delivery_confirmed_at' => 'datetime',
            'cancelled_at'          => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /* ---- the worker's queries ------------------------------------------ */

    /** Due to go out now. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', self::PENDING)
            ->where(fn (Builder $q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where('attempts', '<', self::MAX_ATTEMPTS);
    }

    /** Failed, or gave up. Shown on the admin dashboard as needing a person. */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('status', self::FAILED)
            ->orWhere(fn (Builder $inner) => $inner
                ->where('status', self::PENDING)
                ->where('attempts', '>=', self::MAX_ATTEMPTS)));
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** May a person retry it by hand? Never a completed one. */
    public function isRetryable(): bool
    {
        return in_array($this->status, [self::FAILED, self::PENDING], true)
            && $this->sent_at === null;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? 'Waiting to go out';
    }

    /**
     * What the admin screen is allowed to claim.
     *
     * "Sent" means handed over. Only a confirmed provider event upgrades that
     * to "delivered", and nothing here invents one.
     */
    public function deliveryLabel(): string
    {
        if ($this->delivery_status === 'delivered' && $this->delivery_confirmed_at) {
            return 'Delivered, confirmed ' . $this->delivery_confirmed_at->diffForHumans();
        }

        return match ($this->status) {
            self::SENT   => 'Accepted by the mail server (delivery not confirmed)',
            self::FAILED => 'Failed: ' . ($this->last_error ? \Illuminate\Support\Str::limit($this->last_error, 120) : 'no detail recorded'),
            default      => $this->statusLabel(),
        };
    }
}
