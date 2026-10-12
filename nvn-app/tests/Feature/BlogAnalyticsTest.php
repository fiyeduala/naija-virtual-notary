<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * What the view counter counts, and — more to the point — what it refuses to.
 *
 * Every test here is about a number not going up. That is the whole value of
 * the feature: a count anybody can inflate, that rises when the author reloads
 * their own article, or that climbs by four because a link preview fetched the
 * page, is worse than no count at all, because it will be believed.
 */
class BlogAnalyticsTest extends TestCase
{
    use RefreshDatabase, Fixtures;

    /** A plausible desktop browser, so the crawler check lets it through. */
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36';

    private function makePost(array $overrides = []): Post
    {
        return Post::create(array_merge([
            'title'        => 'Why notarization went online',
            'slug'         => 'why-notarization-went-online',
            'body'         => '<p>' . str_repeat('A sentence about notarization. ', 200) . '</p>',
            'status'       => 'published',
            'published_at' => now()->subDay(),
        ], $overrides));
    }

    private function open(Post $post, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(array_merge(['User-Agent' => self::BROWSER], $headers))
            ->get(route('blog.show', $post));
    }

    public function test_opening_an_article_records_one_view(): void
    {
        $post = $this->makePost();

        $this->open($post)->assertOk();

        $this->assertSame(1, $post->views()->count());
        $this->assertNull($post->views()->first()->read_at);
    }

    /**
     * The same person reloading all afternoon is still one view.
     *
     * This is enforced by a UNIQUE index on (post_id, visitor_hash, view_date)
     * rather than a check in PHP, because two tabs opening at once would pass
     * any such check and the count would drift upward for ever.
     */
    public function test_the_same_visitor_is_counted_once_a_day(): void
    {
        $post = $this->makePost();

        $this->open($post);
        $this->open($post);
        $this->open($post);

        $this->assertSame(1, $post->views()->count());
    }

    public function test_the_same_visitor_is_counted_again_tomorrow(): void
    {
        $post = $this->makePost();

        $this->open($post);

        $this->travel(1)->days();
        $this->open($post);

        $this->assertSame(2, $post->views()->count());
    }

    /**
     * An admin checking their own article is its most devoted reader, and
     * counting them would flatter every article on the site.
     */
    public function test_an_admin_preview_is_not_counted(): void
    {
        $post  = $this->makePost(['status' => 'draft', 'published_at' => null]);
        $admin = $this->makeClient(['role' => 'admin']);

        $this->actingAs($admin);
        $this->open($post)->assertOk();

        $this->assertSame(0, PostView::count());
    }

    public function test_a_crawler_is_not_counted(): void
    {
        $post = $this->makePost();

        $this->open($post, ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']);
        $this->open($post, ['User-Agent' => 'WhatsApp/2.23']);
        $this->open($post, ['User-Agent' => 'facebookexternalhit/1.1']);

        $this->assertSame(0, PostView::count());
    }

    /** An unpublished article has no audience, so it has no numbers. */
    public function test_a_draft_records_nothing_for_a_stranger(): void
    {
        $post = $this->makePost(['status' => 'draft', 'published_at' => null]);

        $this->open($post)->assertNotFound();

        $this->assertSame(0, PostView::count());
    }

    /** A referring site is kept; the URL it came with is not. */
    public function test_only_the_referring_host_is_stored(): void
    {
        $post = $this->makePost();

        $this->open($post, ['referer' => 'https://www.google.com/search?q=notary+lagos&utm_id=92']);

        $this->assertSame('google.com', $post->views()->first()->referrer_host);
    }

    /**
     * A view becomes a read only when the beacon says so.
     *
     * Opening a page is not reading it, and the whole point of the second
     * number is that it means something the first one does not.
     */
    public function test_a_view_is_not_a_read_until_the_beacon_fires(): void
    {
        $post = $this->makePost();

        $this->open($post);
        $this->assertSame(0, $post->views()->read()->count());

        $this->withHeaders(['User-Agent' => self::BROWSER])
            ->post(route('blog.read', $post), ['seconds' => 90])
            ->assertNoContent();

        $view = $post->views()->first();

        $this->assertNotNull($view->read_at);
        $this->assertSame(90, $view->seconds);
    }

    /** A beacon firing twice must not count twice. */
    public function test_marking_a_read_is_idempotent(): void
    {
        $post = $this->makePost();
        $this->open($post);

        $this->withHeaders(['User-Agent' => self::BROWSER])
            ->post(route('blog.read', $post), ['seconds' => 40]);

        $first = $post->views()->first()->read_at;

        $this->travel(5)->minutes();

        $this->withHeaders(['User-Agent' => self::BROWSER])
            ->post(route('blog.read', $post), ['seconds' => 999]);

        $view = $post->views()->first();

        $this->assertSame(1, $post->views()->read()->count());
        $this->assertTrue($first->equalTo($view->read_at));
        $this->assertSame(40, $view->seconds);
    }

    /**
     * The row to stamp is derived from the sender, never taken from the body.
     *
     * There is no view id or post id in a beacon call to tamper with, so the
     * worst a forged one can do is mark the sender's own visit read. Somebody
     * else's visit is out of reach, which is what stops the read count being
     * inflatable by anyone with curl.
     */
    public function test_a_beacon_cannot_mark_another_visitors_view_as_read(): void
    {
        $post = $this->makePost();

        // One real visitor opens it.
        $this->open($post);
        $theirs = $post->views()->first();

        // A different sender — different address, different agent, so a
        // different fingerprint — fires a beacon at the same article.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15'])
            ->post(route('blog.read', $post), ['seconds' => 600])
            ->assertNoContent();

        $this->assertNull($theirs->fresh()->read_at);
        $this->assertSame(0, $post->views()->read()->count());
    }

    /** A beacon for a visit that was never recorded has nothing to stamp. */
    public function test_a_beacon_with_no_matching_view_does_nothing(): void
    {
        $post = $this->makePost();

        $this->withHeaders(['User-Agent' => self::BROWSER])
            ->post(route('blog.read', $post), ['seconds' => 120])
            ->assertNoContent();

        $this->assertSame(0, PostView::count());
    }

    /** An hour is the ceiling; a tab left open all weekend is not a read. */
    public function test_a_reported_duration_is_clamped(): void
    {
        $post = $this->makePost();
        $this->open($post);

        $this->withHeaders(['User-Agent' => self::BROWSER])
            ->post(route('blog.read', $post), ['seconds' => 400000]);

        $this->assertSame(3600, $post->views()->first()->seconds);
    }

    /**
     * The article must survive its own analytics.
     *
     * A view counter is worth nothing; an article returning 500 to a visitor
     * who arrived from a paid advert is worth less than nothing. Every public
     * method on the tracker swallows its own failures, and this proves the
     * page still renders when the tracker cannot write at all.
     */
    public function test_a_tracking_failure_does_not_break_the_article(): void
    {
        $post = $this->makePost();

        // The table the tracker writes to, gone. Nothing short of this proves
        // the catch, because a stubbed tracker would only prove that a tracker
        // which does nothing breaks nothing.
        Schema::drop('post_views');

        $this->open($post)->assertOk()->assertSee($post->title, false);
    }

    /** The visitor hash is a salted digest, not an address. */
    public function test_a_visitor_is_never_stored_as_an_address(): void
    {
        $post = $this->makePost();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->withHeaders(['User-Agent' => self::BROWSER])
            ->get(route('blog.show', $post));

        $hash = $post->views()->first()->visitor_hash;

        $this->assertSame(64, strlen($hash));
        $this->assertStringNotContainsString('198.51.100.77', $hash);
        $this->assertNotSame(hash('sha256', '198.51.100.77'), $hash);
    }

    /** The percentage the admin screens quote comes from one place. */
    public function test_read_through_rate_is_reads_over_views(): void
    {
        $post = $this->makePost();

        $this->assertSame(0, $post->readThroughRate());

        foreach (['a', 'b', 'c', 'd'] as $i => $who) {
            PostView::create([
                'post_id'      => $post->id,
                'visitor_hash' => str_repeat($who, 64),
                'view_date'    => today(),
                'read_at'      => $i < 3 ? now() : null,
            ]);
        }

        $this->assertSame(75, $post->fresh()->readThroughRate());
    }

    /** Our own pages are navigation, not a traffic source. */
    public function test_our_own_host_is_not_a_referrer(): void
    {
        $post = $this->makePost();

        $this->open($post, ['referer' => config('app.url') . '/blog']);

        $this->assertNull($post->views()->first()->referrer_host);
    }
}
