<?php

namespace App\Http\Controllers;

use App\Http\Requests\Blog\PostCommentRequest;
use App\Models\Post;
use App\Models\PostComment;
use App\Notifications\Admin\PostCommentReceived;
use App\Support\AdminAlert;
use App\Support\AuditLogger;
use App\Support\VisitorFingerprint;
use Illuminate\Http\RedirectResponse;

class PostCommentController extends Controller
{
    /**
     * Take a comment from a reader and hold it for moderation.
     *
     * Nothing here publishes anything. Every comment lands `pending` and is
     * invisible to the public until an admin approves it, which is why the
     * reply below says "once we have had a look" rather than thanking them for
     * a contribution that is now live.
     */
    public function store(PostCommentRequest $request, string $post): RedirectResponse
    {
        // Published only. Commenting on a draft would mean commenting on
        // something that is not public yet, and the only person who can open
        // one is an admin.
        $article = Post::published()->where('slug', $post)->firstOrFail();

        // A reply must be to a comment on THIS article, and to one that is
        // already public. Checking existence in the rules is not enough: an id
        // from another article's thread exists just as validly, and accepting
        // it would file the reply under a post nobody is looking at.
        $parentId = $request->integer('parent_id') ?: null;

        if ($parentId !== null) {
            $parent = PostComment::where('id', $parentId)
                ->where('post_id', $article->id)
                ->approved()
                ->first();

            if (! $parent) {
                return back()
                    ->withInput()
                    ->withErrors(['body' => 'The comment you replied to is no longer available.']);
            }
        }

        $comment = PostComment::create([
            'post_id'      => $article->id,
            'user_id'      => $request->user()?->id,
            'parent_id'    => $parentId,
            'author_name'  => $request->string('author_name')->trim()->value(),
            'author_email' => $request->string('author_email')->trim()->lower()->value(),
            'body'         => $request->string('body')->trim()->value(),
            'status'       => 'pending',
            'visitor_hash' => VisitorFingerprint::for($request),
        ]);

        AuditLogger::record('post_comment.received', 'post_comment', $comment->id, [
            'post_id' => $article->id,
            'replied' => $parentId !== null,
        ], $request->user()?->id);

        AdminAlert::send(new PostCommentReceived($comment));

        return back(fallback: route('blog.show', $article))
            ->with('comment_status', 'Thank you — your comment has been sent to us and will'
                . ' appear once we have had a look at it.')
            ->withFragment('comments');
    }
}
