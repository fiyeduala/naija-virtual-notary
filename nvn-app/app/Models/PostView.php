<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person, one article, one day.
 *
 * A row means the page was opened. `read_at` means they actually read it —
 * see BlogTracker::markRead().
 */
class PostView extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'read_at'   => 'datetime',
            'view_date' => 'date',
            'seconds'   => 'integer',
        ];
    }

    public function post(): BelongsTo { return $this->belongsTo(Post::class); }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** Opened, and then genuinely read rather than bounced off. */
    public function scopeRead(Builder $query): Builder
    {
        return $query->whereNotNull('read_at');
    }

    /** A reader we can put a name to, which is the signed-in minority. */
    public function scopeIdentified(Builder $query): Builder
    {
        return $query->whereNotNull('user_id');
    }

    public function wasRead(): bool
    {
        return $this->read_at !== null;
    }
}
