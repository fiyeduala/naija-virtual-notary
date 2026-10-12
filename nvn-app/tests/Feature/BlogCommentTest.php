<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostComment;
use App\Notifications\Admin\PostCommentReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * A public form that writes to the database and renders back to strangers.
 *
 * Two things are being defended, and they are different things:
 *
 *  - Nothing a stranger types reaches another reader without a decision. Every
 *    comment lands pending, so the worst a spam run achieves is a queue.
 *  - Nothing a stranger types is ever rendered as markup. HtmlSanitizer, which
 *    guards Post::body, exists to let some tags through, which is exactly the
 *    wrong tool here. Comments are escaped, and the order matters: e() first,
 *    then nl2br, so the only tags in the output are our own line breaks.
 */
class BlogCommentTest extends TestCase
{
    use RefreshDatabase, Fixtures;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function makePost(array $overrides = []): Post
    {
        return Post::create(array_merge([
            'title'        => 'Notarizing a power of attorney',
            'slug'         => 'notarizing-a-power-of-attorney',
            'body'         => '<p>How it works.</p>',
            'status'       => 'published',
            'published_at' => now()->subDay(),
        ], $overrides));
    }

    /**
     * A valid submission. `posted_at` is an encrypted render time, so the
     * minimum-delay check cannot be back-dated by whoever is posting.
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'author_name'  => 'Chidinma Okafor',
            'author_email' => 'chidinma@example.com',
            'body'         => "Useful piece.\nIs an affidavit the same thing?",
            'website'      => '',
            'posted_at'    => Crypt::encryptString((string) (time() - 30)),
        ], $overrides);
    }

    private function submit(Post $post, array $overrides = [])
    {
        return $this->withHeaders(['User-Agent' => self::BROWSER])
            ->post(route('blog.comment', $post), $this->payload($overrides));
    }

    private function read(Post $post)
    {
        return $this->withHeaders(['User-Agent' => self::BROWSER])
            ->get(route('blog.show', $post));
    }

    public function test_a_comment_lands_pending_and_is_invisible_on_the_article(): void
    {
        $post = $this->makePost();

        $this->submit($post)->assertRedirect();

        $comment = PostComment::sole();

        $this->assertTrue($comment->isPending());
        $this->assertSame($post->id, $comment->post_id);
        $this->assertNull($comment->user_id);
        $this->assertSame(64, strlen($comment->visitor_hash));

        $this->read($post)->assertOk()->assertDontSee('Is an affidavit the same thing?');
    }

    public function test_approving_a_comment_puts_it_on_the_article(): void
    {
        $post = $this->makePost();
        $this->submit($post);

        PostComment::sole()->update(['status' => 'approved', 'approved_at' => now()]);

        $this->read($post)
            ->assertOk()
            ->assertSee('Is an affidavit the same thing?', false)
            ->assertSee('Chidinma Okafor');
    }

    /**
     * The one that matters. Typed markup comes back as visible characters, not
     * as an element — and the commenter's email never appears at all.
     */
    public function test_markup_in_a_comment_is_shown_as_text(): void
    {
        $post = $this->makePost();

        $this->submit($post, [
            'body' => '<script>alert(1)</script><b>bold</b> <a href="http://spam.test">link</a>',
        ]);

        PostComment::sole()->update(['status' => 'approved', 'approved_at' => now()]);

        $html = $this->read($post)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringNotContainsString('href="http://spam.test"', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('chidinma@example.com', $html);
    }

    /** Our own line breaks are the only tags in the output. */
    public function test_line_breaks_survive_as_breaks(): void
    {
        $post = $this->makePost();
        $this->submit($post);

        PostComment::sole()->update(['status' => 'approved', 'approved_at' => now()]);

        $this->read($post)->assertSee('Useful piece.<br />', false);
    }

    public function test_a_filled_honeypot_is_rejected(): void
    {
        $post = $this->makePost();

        $this->submit($post, ['website' => 'http://buy-pills.test'])
            ->assertSessionHasErrors('website');

        $this->assertSame(0, PostComment::count());
    }

    /** Three seconds. A person reading an article cannot type faster. */
    public function test_an_instant_submission_is_rejected(): void
    {
        $post = $this->makePost();

        $this->submit($post, ['posted_at' => Crypt::encryptString((string) time())])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, PostComment::count());
    }

    /**
     * A timestamp that does not decrypt is refused rather than waved through.
     *
     * The opposite choice — treating an unreadable value as "no delay to
     * check" — would make the delay check optional for anyone who sends
     * rubbish in that field, which is everyone it was built to stop.
     */
    public function test_a_forged_timestamp_is_rejected(): void
    {
        $post = $this->makePost();

        $this->submit($post, ['posted_at' => '0'])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, PostComment::count());
    }

    public function test_a_signed_in_commenter_is_named_from_their_account(): void
    {
        $post   = $this->makePost();
        $client = $this->makeClient(['full_name' => 'Tunde Bello', 'email' => 'tunde@example.com']);

        $this->actingAs($client);

        $this->withHeaders(['User-Agent' => self::BROWSER])
            ->post(route('blog.comment', $post), $this->payload([
                'author_name'  => 'Somebody Else',
                'author_email' => 'nobody@example.com',
            ]));

        $comment = PostComment::sole();

        $this->assertSame($client->id, $comment->user_id);
        $this->assertSame('Tunde Bello', $comment->author_name);
        $this->assertSame('tunde@example.com', $comment->author_email);
        $this->assertTrue($comment->fromAccount());
    }

    public function test_the_admin_is_alerted_when_a_comment_arrives(): void
    {
        $post  = $this->makePost();
        $admin = $this->makeClient(['role' => 'admin']);

        $this->submit($post);

        Notification::assertSentTo($admin, PostCommentReceived::class);
    }

    /** A reply must belong to the thread it claims to. */
    public function test_a_reply_cannot_be_attached_to_another_articles_comment(): void
    {
        $post  = $this->makePost();
        $other = $this->makePost(['title' => 'Another article', 'slug' => 'another-article']);

        $elsewhere = PostComment::create([
            'post_id'      => $other->id,
            'author_name'  => 'Someone',
            'author_email' => 'someone@example.com',
            'body'         => 'On the other article.',
            'status'       => 'approved',
            'approved_at'  => now(),
        ]);

        // The message lands on `body`, not on `parent_id`: the form has no
        // visible field for a parent, so an error keyed to it would be held by
        // the session and shown to nobody.
        $this->submit($post, ['parent_id' => $elsewhere->id])
            ->assertSessionHasErrors('body');

        $this->assertSame(1, PostComment::count());
    }

    /** A reply to something nobody has approved yet is a reply to nothing. */
    public function test_a_reply_cannot_be_attached_to_a_pending_comment(): void
    {
        $post = $this->makePost();
        $this->submit($post);

        $pending = PostComment::sole();

        $this->submit($post, ['parent_id' => $pending->id])
            ->assertSessionHasErrors('body');

        $this->assertSame(1, PostComment::count());
    }

    /**
     * An approved reply under a parent nobody approved cannot surface on its
     * own, because replies are read through the parent.
     */
    public function test_an_approved_reply_stays_hidden_under_an_unapproved_parent(): void
    {
        $post = $this->makePost();

        $parent = PostComment::create([
            'post_id'      => $post->id,
            'author_name'  => 'Pending Person',
            'author_email' => 'pending@example.com',
            'body'         => 'Still waiting on a decision.',
            'status'       => 'pending',
        ]);

        PostComment::create([
            'post_id'      => $post->id,
            'parent_id'    => $parent->id,
            'author_name'  => 'Replier',
            'author_email' => 'replier@example.com',
            'body'         => 'A perfectly good reply.',
            'status'       => 'approved',
            'approved_at'  => now(),
        ]);

        $this->read($post)
            ->assertOk()
            ->assertDontSee('A perfectly good reply.')
            ->assertDontSee('Still waiting on a decision.');
    }

    public function test_a_draft_article_takes_no_comments(): void
    {
        $post = $this->makePost(['status' => 'draft', 'published_at' => null]);

        $this->submit($post)->assertNotFound();

        $this->assertSame(0, PostComment::count());
    }
}
