@extends('layouts.public', [
    'title' => $post->title . ' — Naija Virtual Notary',
    'description' => $post->meta_description ?: $post->summary(160),
])

@push('styles')
<style>
    .article-head { padding: 56px 24px 0; }
    .article-wrap { max-width: 760px; margin: 0 auto; }
    .article-back { font-size: 14px; color: var(--brand); font-weight: 600; }
    .article-back:hover { color: var(--brand-dark); }
    .article-title {
        font-size: clamp(28px, 4vw, 42px); font-weight: 800; line-height: 1.22;
        margin: 18px 0 14px;
    }
    .article-meta { font-size: 14px; color: var(--muted); display: flex; gap: 8px; align-items: center; }
    .article-meta .dot { width: 3px; height: 3px; border-radius: 50%; background: var(--muted); }
    .article-cover {
        width: 100%; border-radius: var(--radius); margin: 32px 0 8px;
        aspect-ratio: 16/8; object-fit: cover;
    }

    .draft-notice {
        background: #fff8e1; border: 1px solid #f0d68a; color: #7a5b00;
        border-radius: var(--radius); padding: 14px 18px; margin: 24px 0 0;
        font-size: 14.5px;
    }

    /* The article body. Every one of these selectors is scoped to .prose,
       because this markup comes from the editor and the WordPress import and
       must not be able to restyle the rest of the page. */
    .prose { font-size: 17px; line-height: 1.75; color: var(--ink); }
    .prose > * + * { margin-top: 1.15em; }
    .prose h2 { font-size: 26px; font-weight: 700; line-height: 1.3; margin-top: 1.8em; }
    .prose h3 { font-size: 21px; font-weight: 700; line-height: 1.35; margin-top: 1.6em; }
    .prose p { color: #364150; }
    .prose a { color: var(--brand-dark); text-decoration: underline; }
    .prose ul, .prose ol { padding-left: 1.4em; }
    .prose li + li { margin-top: .4em; }
    .prose img { border-radius: var(--radius); display: block; height: auto; }
    .prose figure { margin: 1.6em 0; }
    .prose figcaption { font-size: 13.5px; color: var(--muted); text-align: center; margin-top: 8px; }
    .prose blockquote {
        border-left: 3px solid var(--brand); padding: 4px 0 4px 20px;
        color: var(--muted); font-style: italic;
    }
    .prose pre {
        background: #0f1a0b; color: #e6f4e0; padding: 16px 18px;
        border-radius: 10px; overflow-x: auto; font-size: 14px;
    }
    .prose code { font-size: .92em; }
    .prose :not(pre) > code { background: var(--brand-light); padding: 2px 6px; border-radius: 5px; }
    .prose table { width: 100%; border-collapse: collapse; font-size: 15px; display: block; overflow-x: auto; }
    .prose th, .prose td { border: 1px solid var(--line); padding: 10px 12px; text-align: left; }
    .prose th { background: var(--bg); font-weight: 600; }

    .article-cta {
        margin-top: 56px; background: var(--brand-light); border-radius: var(--radius);
        padding: 32px; text-align: center;
    }
    .article-cta h3 { font-size: 21px; font-weight: 700; margin-bottom: 8px; }
    .article-cta p { color: var(--muted); margin-bottom: 20px; }

    .more-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-top: 24px; }
    @media (max-width: 780px) { .more-grid { grid-template-columns: 1fr; } }
    .more-card {
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--radius); padding: 20px;
        transition: box-shadow .18s;
    }
    .more-card:hover { box-shadow: var(--shadow); }
    .more-card h4 { font-size: 16px; font-weight: 600; line-height: 1.4; margin-bottom: 8px; }
    .more-card span { font-size: 12.5px; color: var(--muted); }

    /* ---- Comments ---------------------------------------------------- */
    .comments-block { border-top: 1px solid var(--line); }
    .comments-head { font-size: 20px; font-weight: 700; margin-bottom: 20px; }
    .comment {
        border: 1px solid var(--line); border-radius: var(--radius);
        padding: 16px 18px; margin-bottom: 14px; background: var(--surface);
    }
    .comment-reply { margin: 14px 0 0 24px; background: var(--bg); }
    .comment-meta {
        display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
        font-size: 13.5px; color: var(--muted); margin-bottom: 8px;
    }
    .comment-meta strong { color: var(--ink); font-size: 14.5px; font-weight: 600; }
    .comment-tag {
        font-size: 11.5px; font-weight: 600; color: var(--brand-dark);
        background: var(--brand-light); border-radius: 20px; padding: 2px 9px;
    }
    .comment-body { font-size: 15.5px; line-height: 1.65; color: #364150; }
    .comment-reply-btn {
        margin-top: 10px; font-size: 13px; font-weight: 600; color: var(--brand);
        background: none; border: 0; padding: 0; cursor: pointer;
    }
    .comment-reply-btn:hover { color: var(--brand-dark); }
    .comment-empty { color: var(--muted); font-size: 15px; margin-bottom: 24px; }
    .comment-note {
        background: var(--brand-light); border-radius: var(--radius);
        padding: 14px 18px; font-size: 14.5px; color: var(--brand-dark);
        margin-bottom: 20px;
    }
    .comment-error { color: #b3261e; font-size: 13.5px; margin-top: 6px; }
    .comment-form { margin-top: 32px; }
    .comment-form-head { font-size: 17px; font-weight: 700; margin-bottom: 14px; }
    .comment-form label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
    .comment-form input[type="text"],
    .comment-form input[type="email"],
    .comment-form textarea {
        width: 100%; border: 1px solid var(--line); border-radius: 10px;
        padding: 11px 13px; font: inherit; font-size: 15px; background: var(--surface);
    }
    .comment-form textarea { min-height: 130px; resize: vertical; }
    .comment-body-field { display: block; margin-bottom: 16px; }
    .comment-form small { display: block; font-size: 12.5px; color: var(--muted); font-weight: 400; margin-top: 5px; }
    .comment-smallprint { font-size: 13px; color: var(--muted); margin-top: 12px; }
    .comment-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 640px) { .comment-fields { grid-template-columns: 1fr; } }
    .comment-as { font-size: 14.5px; color: var(--muted); margin-bottom: 16px; }
    .comment-replying {
        font-size: 14px; background: var(--bg);
        border: 1px solid var(--line); border-radius: 10px;
        padding: 10px 13px; margin-bottom: 16px;
    }
    .comment-replying button {
        background: none; border: 0; color: var(--brand); font-weight: 600;
        cursor: pointer; padding: 0; margin-left: 8px; font-size: 13px;
    }

    /* The honeypot. Off-screen rather than display:none, because some bots
       skip fields that are not rendered at all. */
    .comment-hp {
        position: absolute; left: -9999px; width: 1px; height: 1px;
        overflow: hidden;
    }
</style>
@endpush

@section('content')

<div class="article-head">
    <div class="article-wrap">
        <a href="{{ route('blog.index') }}" class="article-back">← All articles</a>

        <h1 class="article-title">{{ $post->title }}</h1>

        <div class="article-meta">
            <span>{{ ($post->published_at ?? $post->created_at)->format('j F Y') }}</span>
            <span class="dot"></span>
            <span>{{ $post->readingMinutes() }} min read</span>
            @if ($post->author)
                <span class="dot"></span>
                <span>{{ $post->author->full_name }}</span>
            @endif
        </div>

        @unless ($post->isPublished())
            <p class="draft-notice">
                <strong>Not live.</strong>
                {{ $post->published_at?->isFuture()
                    ? 'Scheduled for ' . $post->published_at->format('j F Y, g:ia') . '.'
                    : 'This is a draft.' }}
                Only signed-in admins can see this page.
            </p>
        @endunless

        @if ($cover = $post->coverUrl())
            <img src="{{ $cover }}" alt="" class="article-cover">
        @endif
    </div>
</div>

<section class="section-sm">
    <div class="article-wrap">
        {{--
            The one place in this application that renders stored HTML
            unescaped. It is safe only because App\Support\HtmlSanitizer runs on
            every write to Post::body, and because nobody below admin can write
            to it. Do not copy this line anywhere else, and never pass this
            value through Blade::render — it is content, not a template.
        --}}
        <div class="prose">{!! $post->body !!}</div>

        <div class="article-cta">
            <h3>Need a document notarized?</h3>
            <p>Get it done online, from anywhere in the world.</p>
            <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Get started</a>
        </div>

        @if ($recent->isNotEmpty())
            <h3 style="margin-top:56px;font-size:20px;font-weight:700">More reading</h3>
            <div class="more-grid">
                @foreach ($recent as $other)
                    <a href="{{ route('blog.show', $other) }}" class="more-card">
                        <h4>{{ $other->title }}</h4>
                        <span>{{ $other->published_at?->format('j M Y') }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</section>

@include('public.blog._comments', ['post' => $post, 'comments' => $comments])

{{--
    The read beacon.

    Opening a page is not reading it. This marks the visit as a genuine read
    only once the visitor has got 60% of the way down AND spent at least a
    quarter of the article's own estimated reading time on it, so a long
    article demands more of both than a short one, with a 15-second floor.

    It posts nothing but the slug and a duration. The row it stamps is derived
    server-side from the fingerprint of the request, so there is no id here to
    tamper with and the worst a forged call can do is mark the sender's own
    visit read early.
--}}
<script>
(function () {
    var url     = @json(route('blog.read', $post));
    var token   = @json(csrf_token());
    var minimum = Math.max(15, Math.round({{ $post->readingMinutes() }} * 60 * 0.25));
    var started = Date.now();
    var deepest = 0;
    var sent    = false;

    function depth() {
        var scrollable = document.documentElement.scrollHeight - window.innerHeight;

        // A screen with nothing to scroll has no depth to measure, so the
        // time floor alone decides whether it was read.
        if (scrollable <= 0) {
            return 100;
        }

        return Math.round((window.scrollY / scrollable) * 100);
    }

    function seconds() {
        return Math.round((Date.now() - started) / 1000);
    }

    function send() {
        if (sent || deepest < 60 || seconds() < minimum) {
            return;
        }

        sent = true;

        var body = new FormData();
        body.append('_token', token);
        body.append('seconds', seconds());

        if (navigator.sendBeacon) {
            navigator.sendBeacon(url, body);
        } else {
            fetch(url, { method: 'POST', body: body, keepalive: true });
        }
    }

    window.addEventListener('scroll', function () {
        var now = depth();

        if (now > deepest) {
            deepest = now;
        }
    }, { passive: true });

    setTimeout(send, minimum * 1000 + 500);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            send();
        }
    });

    // pagehide rather than unload, which some browsers no longer fire at all.
    window.addEventListener('pagehide', send);
})();
</script>

<script>
(function () {
    var parent   = document.getElementById('comment-parent');
    var banner   = document.getElementById('comment-replying');
    var heading  = document.getElementById('comment-form-head');
    var cancel   = document.getElementById('comment-cancel-reply');
    var target   = document.getElementById('comment-replying-name');

    if (!parent) {
        return;
    }

    document.querySelectorAll('[data-reply-to]').forEach(function (button) {
        button.addEventListener('click', function () {
            parent.value       = button.getAttribute('data-reply-to');
            target.textContent = button.getAttribute('data-reply-name');
            banner.hidden      = false;
            heading.textContent  = 'Write a reply';
            document.getElementById('comment-body').focus({ preventScroll: true });
            banner.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });

    cancel.addEventListener('click', function () {
        parent.value         = '';
        banner.hidden        = true;
        heading.textContent  = 'Leave a comment';
    });
})();
</script>

@endsection
