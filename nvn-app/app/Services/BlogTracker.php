<?php

namespace App\Services;

use App\Models\Post;
use App\Models\PostView;
use App\Support\VisitorFingerprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Counting blog readers, without ever being allowed to break the blog.
 *
 * Every public method here swallows its own failures and logs them. That is
 * deliberate and is the most important thing about this class: a view counter
 * is worth nothing, and an article that returns a 500 to a visitor who arrived
 * from an advert is worth a great deal less than nothing. If the table is
 * missing, locked or full, the page still renders and the count is simply
 * lost.
 */
class BlogTracker
{
    /**
     * Record that this article was opened, if it was opened by a reader.
     *
     * Returns the row, or null when the visit was not counted — which is not
     * an error and happens on most of the cases below.
     */
    public function record(Post $post, Request $request): ?PostView
    {
        try {
            if (! $this->countable($post, $request)) {
                return null;
            }

            // firstOrCreate against the unique index, so two tabs opening at
            // once settle on one row instead of inflating the count. The
            // second insert loses the race, throws, and is caught below —
            // where we read the winner back rather than reporting a failure.
            return PostView::firstOrCreate(
                [
                    'post_id'      => $post->id,
                    'visitor_hash' => VisitorFingerprint::for($request),
                    'view_date'    => now()->toDateString(),
                ],
                [
                    'user_id'       => $request->user()?->id,
                    'referrer_host' => $this->referrerHost($request),
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Blog view not recorded: ' . $e->getMessage(), [
                'post_id' => $post->id,
            ]);

            return null;
        }
    }

    /**
     * Stamp this visitor's row for this article as genuinely read.
     *
     * The row is found from the request itself — the article, plus the hash of
     * the person asking, plus today. Nothing identifying a row is accepted
     * from the caller, so the beacon cannot be replayed at somebody else's
     * visit or at an article it never opened; the worst a forged call can do
     * is mark the sender's own view read a little early.
     *
     * Idempotent: a second call on an already-read row changes nothing, so a
     * beacon that fires twice on a flaky connection cannot double-count.
     */
    public function markRead(Post $post, Request $request, ?int $seconds = null): bool
    {
        try {
            $view = PostView::where('post_id', $post->id)
                ->where('visitor_hash', VisitorFingerprint::for($request))
                ->whereDate('view_date', now()->toDateString())
                ->first();

            if (! $view || $view->wasRead()) {
                return false;
            }

            $view->update([
                'read_at' => now(),
                // Clamped. The browser hands us whatever it likes, and a tab
                // left open over a weekend would otherwise land a number that
                // makes every average meaningless.
                'seconds' => $seconds === null ? null : max(0, min($seconds, 3600)),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Blog read not recorded: ' . $e->getMessage(), [
                'post_id' => $post->id,
            ]);

            return false;
        }
    }

    /**
     * Is this a visit worth counting at all?
     *
     * Four exclusions, each of which would otherwise bend the numbers in the
     * same direction — upward, and in a way that flatters the article:
     *
     * - Crawlers and link-preview fetchers, which are most of the traffic to a
     *   page nobody has promoted yet.
     * - Admins, who are the people checking the article and would otherwise be
     *   its most devoted readers.
     * - Drafts and scheduled articles, which only an admin can see anyway, so
     *   a count on them could only ever be that admin.
     */
    private function countable(Post $post, Request $request): bool
    {
        if (! $post->isPublished()) {
            return false;
        }

        if ($request->user()?->isAdmin()) {
            return false;
        }

        return ! VisitorFingerprint::isCrawler($request);
    }

    /**
     * The host that sent them, or null if they arrived directly.
     *
     * Host only, never the full URL: a referring URL carries query strings,
     * and those carry campaign ids, search terms and occasionally personal
     * data that there is no reason to keep. Our own host is discarded too —
     * that is a reader moving around the site, not a source of traffic.
     */
    private function referrerHost(Request $request): ?string
    {
        $referrer = $request->headers->get('referer');

        if (! $referrer) {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./', '', $host));

        return $host === strtolower(preg_replace('/^www\./', '', $request->getHost()))
            ? null
            : substr($host, 0, 120);
    }
}
