<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\Filament\Resources\PostResource;
use App\Models\Post;
use App\Models\PostView;
use App\Support\Analytics;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * What one article actually did.
 *
 * The honest limits of it, stated here because they are the first thing
 * somebody reading this screen will wonder about:
 *
 * - "Who read it" is only answerable for signed-in readers. A marketing
 *   article's audience is overwhelmingly strangers with no account, so the
 *   named list is a small subset of the count beside it. That is not a bug and
 *   the page says so rather than leaving a short list looking broken.
 * - Nothing before tracking was deployed exists. Views were never written
 *   down, so there is no history to backfill and no number here covers it.
 * - A view is one person, one day. Reloading does not add to it.
 */
class PostAnalytics extends Page
{
    protected static string $resource = PostResource::class;
    protected static string $view = 'filament.pages.post-analytics';
    protected static ?string $title = 'Analytics';

    public Post $record;

    public function mount(int|string $record): void
    {
        $this->record = Post::withoutGlobalScopes()->findOrFail($record);
    }

    public function getHeading(): string|Htmlable
    {
        return $this->record->title;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->record->isPublished()
            ? 'Live since ' . ($this->record->published_at ?? $this->record->created_at)->format('j M Y')
            : 'Not live yet — these are preview numbers, and admins are never counted.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('view')
                ->label('View on site')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn () => route('blog.show', $this->record))
                ->openUrlInNewTab()
                ->visible(fn () => $this->record->isPublished()),

            Actions\EditAction::make()
                ->url(fn () => PostResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    /**
     * Everything the view renders, resolved once.
     *
     * Deliberately not cached: these numbers are read a handful of times a
     * week, and a stale view count is worse than a slow one.
     */
    public function stats(): array
    {
        $views = $this->record->views();

        $total  = (clone $views)->count();
        $read   = (clone $views)->read()->count();
        $people = (clone $views)->distinct('visitor_hash')->count('visitor_hash');

        return [
            'views'    => $total,
            'people'   => $people,
            'reads'    => $read,
            'rate'     => $this->record->readThroughRate(),
            'minutes'  => $this->record->readingMinutes(),
            'dwell'    => (int) round((clone $views)->read()->avg('seconds') ?? 0),
            'comments' => $this->record->comments()->count(),
            'waiting'  => $this->record->comments()->pending()->count(),
        ];
    }

    /** A 30-day shape, so "did the promotion do anything" has an answer. */
    public function series(): array
    {
        return Analytics::dailySeries(
            Analytics::dates($this->record->views()->get(['created_at']), 'created_at'),
        );
    }

    /**
     * The readers we can actually name.
     *
     * Signed-in only, and read rather than merely opened, because a named list
     * of people who bounced is not information anybody acts on.
     */
    public function readers()
    {
        return $this->record->views()
            ->identified()
            ->read()
            ->with('user:id,full_name,email,role')
            ->latest('read_at')
            ->limit(50)
            ->get();
    }

    /** Where they came from. Host only — a referring URL carries campaign ids. */
    public function sources()
    {
        return $this->record->views()
            ->whereNotNull('referrer_host')
            ->selectRaw('referrer_host, COUNT(*) as hits')
            ->groupBy('referrer_host')
            ->orderByDesc('hits')
            ->limit(10)
            ->get();
    }

    public function comments()
    {
        return $this->record->comments()
            ->with('user:id,full_name')
            ->latest()
            ->limit(25)
            ->get();
    }

    /**
     * How many opened it and left without reading. The number the article is
     * judged on, rather than the one it flatters itself with.
     */
    public function bounced(): int
    {
        return (int) PostView::where('post_id', $this->record->id)
            ->whereNull('read_at')
            ->count();
    }
}
