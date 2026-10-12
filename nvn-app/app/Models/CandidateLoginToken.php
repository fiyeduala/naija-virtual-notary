<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One emailed sign-in link.
 *
 * What is stored is the SHA-256 of the token, never the token. A dump of this
 * table, a backup of it or a stray log line containing it grants nobody
 * anything, because the value in the email cannot be recovered from the hash.
 *
 * `consumed_at` is what makes a link one-use. It is set inside the same
 * transaction that creates the session, under a row lock, so two clicks
 * arriving together cannot both succeed.
 *
 * The redemption route is a POST behind an explicit "Yes, sign me in" button.
 * A GET would be fetched by the mail scanners that sit in front of many
 * corporate inboxes, which would burn the token before the candidate ever saw
 * the message — and they would be told their perfectly good link had expired.
 */
class CandidateLoginToken extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** Still usable: not spent, not expired. */
    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }

    /** The hash of a presented token, for the lookup. */
    public static function hashFor(string $token): string
    {
        return hash('sha256', $token);
    }
}
