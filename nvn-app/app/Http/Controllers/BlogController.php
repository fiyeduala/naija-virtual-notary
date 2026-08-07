<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BlogController extends Controller
{
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

        return view('public.blog.show', [
            'post'   => $article,
            'recent' => Post::published()
                ->whereKeyNot($article->id)
                ->latest('published_at')
                ->limit(3)
                ->get(),
        ]);
    }
}
