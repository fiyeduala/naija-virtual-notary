@extends('layouts.public', [
    'title' => 'Blog — Naija Virtual Notary',
    'description' => 'Guides, updates and answers on notarization in Nigeria and for the diaspora.',
])

@push('styles')
<style>
    .page-hero {
        background: linear-gradient(135deg, #0f1a0b 0%, #1a3011 60%, #2a5020 100%);
        color: #fff; padding: 80px 24px 72px; text-align: center;
    }
    .page-hero h1 { font-size: clamp(32px, 4vw, 52px); font-weight: 800; color: #fff; margin-bottom: 16px; }
    .page-hero p { font-size: 18px; color: rgba(255,255,255,.8); max-width: 580px; margin: 0 auto; line-height: 1.6; }

    .post-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; }
    @media (max-width: 940px) { .post-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 640px) { .post-grid { grid-template-columns: 1fr; } }

    .post-card {
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
        overflow: hidden; display: flex; flex-direction: column;
        transition: box-shadow .18s, transform .18s;
    }
    .post-card:hover { box-shadow: var(--shadow); transform: translateY(-3px); }
    .post-card-img { aspect-ratio: 16/9; object-fit: cover; width: 100%; display: block; background: var(--brand-light); }
    .post-card-img-empty {
        aspect-ratio: 16/9; background: var(--brand-light);
        display: flex; align-items: center; justify-content: center; font-size: 40px;
    }
    .post-card-body { padding: 22px; display: flex; flex-direction: column; flex: 1; }
    .post-card h2 { font-size: 19px; font-weight: 700; line-height: 1.35; margin-bottom: 10px; }
    .post-card p { font-size: 14.5px; color: var(--muted); line-height: 1.65; flex: 1; }
    .post-meta {
        margin-top: 16px; font-size: 12.5px; color: var(--muted);
        display: flex; gap: 8px; align-items: center;
    }
    .post-meta .dot { width: 3px; height: 3px; border-radius: 50%; background: var(--muted); }

    .blog-empty {
        text-align: center; padding: 72px 24px; color: var(--muted);
        border: 1px dashed var(--line); border-radius: var(--radius); background: var(--surface);
    }
    .blog-empty .icon { font-size: 44px; margin-bottom: 14px; }

    .pagination-wrap { margin-top: 48px; display: flex; justify-content: center; }
    .pagination-wrap svg { width: 18px; height: 18px; }
</style>
@endpush

@section('content')

<section class="page-hero">
    <h1>From the blog</h1>
    <p>Guides, updates and plain answers about notarizing documents in Nigeria — at home and abroad.</p>
</section>

<section class="section">
    <div class="container">
        @if ($posts->isEmpty())
            <div class="blog-empty">
                <div class="icon">✍️</div>
                <p>Nothing published yet. Check back shortly.</p>
            </div>
        @else
            <div class="post-grid">
                @foreach ($posts as $post)
                    <a href="{{ route('blog.show', $post) }}" class="post-card">
                        @if ($cover = $post->coverUrl())
                            <img src="{{ $cover }}" alt="" class="post-card-img" loading="lazy">
                        @else
                            <div class="post-card-img-empty">📄</div>
                        @endif
                        <div class="post-card-body">
                            <h2>{{ $post->title }}</h2>
                            <p>{{ $post->summary() }}</p>
                            <div class="post-meta">
                                <span>{{ $post->published_at?->format('j M Y') }}</span>
                                <span class="dot"></span>
                                <span>{{ $post->readingMinutes() }} min read</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="pagination-wrap">{{ $posts->links() }}</div>
        @endif
    </div>
</section>

@endsection
