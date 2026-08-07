<?php

namespace App\Models;

use App\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Post extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $post) {
            $post->slug = $post->slug ? Str::slug($post->slug) : null;
            $post->slug ??= static::uniqueSlug($post->title);

            // Publishing without saying when means now. Left null, the article
            // would be marked published and still be invisible, because
            // scopePublished filters on the date.
            if ($post->status === 'published' && $post->published_at === null) {
                $post->published_at = now();
            }
        });
    }

    /**
     * Sanitised on the way in, not on the way out.
     *
     * Doing it here means it happens once per save rather than once per reader,
     * and that every route into the column goes through it — the Filament
     * editor, the WordPress importer, and tinker.
     */
    protected function body(): Attribute
    {
        return Attribute::set(fn (?string $value) => HtmlSanitizer::clean($value));
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Live, and not post-dated. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
                     ->whereNotNull('published_at')
                     ->where('published_at', '<=', now());
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published'
            && $this->published_at !== null
            && ! $this->published_at->isFuture();
    }

    public function coverUrl(): ?string
    {
        if (! $this->cover_image || ! Storage::disk('blog')->exists($this->cover_image)) {
            return null;
        }

        return Storage::disk('blog')->url($this->cover_image);
    }

    /** The blurb under the title in a list, falling back to the article itself. */
    public function summary(int $characters = 180): string
    {
        if (filled($this->excerpt)) {
            return Str::limit(strip_tags($this->excerpt), $characters);
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $this->body))), $characters);
    }

    public function readingMinutes(): int
    {
        return max(1, (int) ceil(str_word_count(strip_tags((string) $this->body)) / 200));
    }

    /** A slug nothing else is using, including a soft-deleted article. */
    public static function uniqueSlug(?string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug((string) $title) ?: 'post';
        $slug = $base;
        $n    = 2;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }
}
