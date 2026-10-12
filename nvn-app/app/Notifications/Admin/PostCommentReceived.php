<?php

namespace App\Notifications\Admin;

use App\Models\PostComment;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A reader has commented on an article.
 *
 * No mail channel, following NewUserRegisteredNotification: a comment is worth
 * glancing at rather than worth an email, and a mailed copy of every one is
 * what teaches an admin to stop reading admin mail. It is a queue item, and
 * the badge on the blog is the honest place for it.
 *
 * The body is carried as a short snippet rather than in full. A notification
 * lands on a phone lock screen, and the whole of a stranger's unmoderated
 * writing is not something to put there.
 */
class PostCommentReceived extends Notification
{
    use Queueable;

    public function __construct(public PostComment $comment) {}

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'post_comment',
            'comment_id' => $this->comment->id,
            'post_id'    => $this->comment->post_id,
            'post_title' => $this->comment->post?->title,
            'author'     => $this->comment->author_name,
            'snippet'    => $this->comment->snippet(100),
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'New blog comment',
            'body'  => $this->comment->author_name . ' on "'
                . \Illuminate\Support\Str::limit((string) $this->comment->post?->title, 40) . '"',
            'url'   => route('filament.admin.resources.post-comments.index'),
        ];
    }
}
