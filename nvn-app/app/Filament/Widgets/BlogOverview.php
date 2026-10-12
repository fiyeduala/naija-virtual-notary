<?php

namespace App\Filament\Widgets;

use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostView;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Whether the blog is doing anything.
 *
 * Its own widget rather than four more tiles on PlatformOverview, which is the
 * platform's own health and already full. These answer a different question —
 * is the writing earning its keep — and they are read together or not at all.
 *
 * Hidden until there is a published article, so an installation with an empty
 * blog is not asked to look at four zeros.
 */
class BlogOverview extends BaseWidget
{
    protected static ?int $sort = 9;

    public static function canView(): bool
    {
        return Post::query()->withoutGlobalScopes()->where('status', 'published')->exists();
    }

    protected function getStats(): array
    {
        $month = now()->startOfMonth();

        $views = PostView::where('created_at', '>=', $month)->count();
        $reads = PostView::where('created_at', '>=', $month)->read()->count();

        // All time, not this month. A view count that resets on the 1st makes a
        // young article look like a failure for a week every month.
        $viewsAllTime = PostView::count();

        $waiting  = PostComment::pending()->count();
        $approved = PostComment::approved()->count();

        // The article that actually earned its views, over its whole life.
        // Ranked on reads rather than views, because an article people open and
        // abandon is not the one to write more of.
        $best = Post::query()
            ->withoutGlobalScopes()
            ->withCount(['views as reads_count' => fn ($query) => $query->whereNotNull('read_at')])
            ->orderByDesc('reads_count')
            ->first();

        return [
            Stat::make('Blog views this month', number_format($views))
                ->description('All time: ' . number_format($viewsAllTime))
                ->color($views > 0 ? 'success' : 'gray'),

            Stat::make('Read through', number_format($reads))
                ->description($views > 0
                    ? round($reads / $views * 100) . '% of this month’s views'
                    : 'Nothing opened yet this month')
                ->color($reads > 0 ? 'success' : 'gray'),

            Stat::make('Comments waiting', number_format($waiting))
                ->description($approved . ' live on the site')
                ->color($waiting > 0 ? 'warning' : 'gray'),

            Stat::make('Best performing', $best && $best->reads_count > 0
                    ? str($best->title)->limit(28)->toString()
                    : '—')
                ->description($best && $best->reads_count > 0
                    ? number_format($best->reads_count) . ' ' . str('read')->plural($best->reads_count)
                    : 'No article has been read through yet')
                ->color('gray'),
        ];
    }
}
