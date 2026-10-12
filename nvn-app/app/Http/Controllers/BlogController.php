<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Services\BlogTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BlogController extends Controller
{
    public function __construct(private BlogTracker $tracker) {}

    public function index(): View
    {
        return view('public.blog.index', [
            'posts' => Post::published()
                ->with('author')
                ->latest('published_at')
                ->paginate(9),
        ]);
    }

    public function show(Request $request, string $post): View
    {
        $article = Post::where('slug', $post)->firstOrFail();

        // A draft, or one dated for next Tuesday, is visible to an admin so it
        // can be checked in place before it goes out — and to nobody else.
        abort_unless(
            $article->isPublished() || $request->user()?->isAdmin(),
            404
        );

        // Counted here, and the tracker is responsible for deciding whether
        // this visit counts at all — a crawler, an admin previewing, or a
        // draft all return null. It never throws; see BlogTracker.
        $this->tracker->record($article, $request);

        return view('public.blog.show', [
            'post'   => $article,
            'recent' => Post::published()
                ->whereKeyNot($article->id)
                ->latest('published_at')
                ->limit(3)
                ->get(),

            // Approved, top-level, oldest first. Replies hang off each one and
            // carry the same approval filter.
            'comments' => $article->publicComments()
                ->with(['user:id,full_name', 'replies.user:id,full_name'])
                ->get(),
        ]);
    }

    /**
     * The beacon: this visitor actually read the article.
     *
     * Takes the slug and a duration and nothing else. The row to stamp is
     * worked out from the request itself, so there is no view id to tamper
     * with — see BlogTracker::markRead().
     *
     * Always answers 204, including when nothing was stamped. The browser has
     * no use for the outcome, this fires from sendBeacon() during page unload
     * where no response is read anyway, and an error code here would only
     * clutter a reader's console on a page that is working perfectly.
     */
    public function read(Request $request, string $post): JsonResponse
    {
        $article = Post::published()->where('slug', $post)->first();

        if ($article) {
            $this->tracker->markRead(
                $article,
                $request,
                $request->integer('seconds') ?: null,
            );
        }

        return response()->json(null, 204);
    }
}
