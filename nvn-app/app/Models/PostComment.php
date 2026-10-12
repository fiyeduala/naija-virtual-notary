<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A reader's comment on an article.
 *
 * `body` is plain text in and plain text out. There is no HTML accessor here
 * and there must not be one: the sanitiser used on Post::body exists to let
 * some tags through, which is the opposite of what a stranger's input needs.
 * Every view renders this with Blade's escaping.
 */
class PostComment extends Model
{
    use SoftDeletes;

    /**
     * Pending until an admin looks at it. Nothing auto-approves.
     *
     * `spam` is kept distinct from `rejected` so that "this is junk" and "this
     * is a real person we would rather not publish" stay different facts —
     * only the first is safe to act on in bulk.
     */
    public const STATUSES = ['pending', 'approved', 'spam', 'rejected'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo { return $this->belongsTo(Post::class); }

    /** Set only when the commenter was signed in. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }

    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->approved()->oldest();
    }

    /** What the public is allowed to see. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /** What is waiting on a decision — the navigation badge reads this. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /** Top-level only; replies hang off their parent. */
    public function scopeThreadRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function isApproved(): bool { return $this->status === 'approved'; }

    public function isPending(): bool { return $this->status === 'pending'; }

    public function isSpam(): bool { return $this->status === 'spam'; }

    /** Did a signed-in account write this, or a stranger giving a name? */
    public function fromAccount(): bool { return $this->user_id !== null; }

    /** For the moderation list, where the whole body is rarely needed. */
    public function snippet(int $characters = 120): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', (string) $this->body)), $characters);
    }
}
