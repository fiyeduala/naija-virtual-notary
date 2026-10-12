@extends('layouts.public', [
    'title' => 'Work With Us — Careers | Naija Virtual Notary',
    'description' => 'Fully remote roles at Naija Virtual Notary: customer service, marketing, digital marketing and human resources. See what is open and apply.',
])

{{--
    The openings list.

    Published only. A paused or closed advert keeps its own page so a link
    somebody shared on WhatsApp still opens and still explains itself, but it
    does not appear here — this list is "what you can apply for today".

    Each card states the eligible locations and the required hours next to the
    Remote badge, because remote is not the same as "anywhere, any time" and a
    candidate who discovers that at interview has wasted a week.
--}}

@push('styles')
<style>
    .page-hero {
        background: linear-gradient(135deg, #0f1a0b 0%, #1a3011 60%, #2a5020 100%);
        color: #fff; padding: 80px 24px 72px; text-align: center;
    }
    .page-hero h1 { font-size: clamp(32px, 4vw, 52px); font-weight: 800; color: #fff; margin-bottom: 16px; }
    .page-hero p { font-size: 18px; color: rgba(255,255,255,.8); max-width: 620px; margin: 0 auto; line-height: 1.6; }

    .roles { display: grid; gap: 18px; }
    .role-card {
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
        padding: 26px 28px; display: flex; flex-wrap: wrap; gap: 20px; align-items: center;
        justify-content: space-between; transition: border-color .15s, box-shadow .15s;
    }
    .role-card:hover { border-color: var(--brand); box-shadow: var(--shadow); }
    .role-main { flex: 1 1 420px; min-width: 0; }
    .role-dept {
        font-size: 12px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
        color: var(--brand-dark); margin-bottom: 7px;
    }
    .role-title { font-size: 21px; font-weight: 700; margin-bottom: 12px; }
    .role-title a:hover { color: var(--brand-dark); }
    .role-tags { display: flex; flex-wrap: wrap; gap: 8px; }
    .tag {
        display: inline-block; font-size: 12.5px; font-weight: 500; padding: 5px 11px;
        border-radius: 999px; background: #f1f4f6; color: var(--muted);
    }
    .tag-remote { background: var(--brand-light); color: var(--brand-dark); font-weight: 600; }
    .role-close { font-size: 13.5px; color: var(--muted); margin-top: 12px; }
    .role-close strong { color: var(--ink); font-weight: 600; }

    .empty {
        background: var(--surface); border: 1px dashed var(--line); border-radius: var(--radius);
        padding: 56px 32px; text-align: center;
    }
    .empty h2 { font-size: 22px; font-weight: 700; margin-bottom: 12px; }
    .empty p { color: var(--muted); font-size: 16px; line-height: 1.7; max-width: 520px; margin: 0 auto 20px; }

    .note {
        margin-top: 40px; padding: 22px 26px; background: var(--surface);
        border: 1px solid var(--line); border-left: 3px solid var(--brand); border-radius: var(--radius);
        font-size: 14.5px; color: var(--muted); line-height: 1.7;
    }
    .note strong { color: var(--ink); }
    @media (max-width: 640px) {
        .role-card { padding: 22px; }
        .role-card .btn { width: 100%; justify-content: center; }
    }
</style>
@endpush

@section('content')

<header class="page-hero">
    <h1>Work With Us</h1>
    <p>We notarize documents for Nigerians at home and across the diaspora. Every role below is fully remote.</p>
</header>

<section class="section">
    <div class="container">

        @if ($vacancies->isEmpty())
            <div class="empty">
                <h2>No openings at the moment</h2>
                <p>
                    There is nothing we are hiring for right now. Roles are posted on this page
                    when they open, so it is worth checking back.
                </p>
                <a href="{{ route('home') }}" class="btn btn-outline">Back to the home page</a>
            </div>
        @else
            <div class="roles">
                @foreach ($vacancies as $vacancy)
                    <article class="role-card">
                        <div class="role-main">
                            @if ($vacancy->departmentLabel())
                                <div class="role-dept">{{ $vacancy->departmentLabel() }}</div>
                            @endif

                            <h2 class="role-title">
                                <a href="{{ route('careers.show', $vacancy->slug) }}">{{ $vacancy->title }}</a>
                            </h2>

                            <div class="role-tags">
                                <span class="tag tag-remote">Remote</span>

                                @if (filled($vacancy->employment_type))
                                    <span class="tag">{{ $vacancy->employment_type }}</span>
                                @endif

                                @if (filled($vacancy->location_eligibility))
                                    <span class="tag">{{ $vacancy->location_eligibility }}</span>
                                @endif

                                @if ($vacancy->salaryLine())
                                    <span class="tag">{{ $vacancy->salaryLine() }}</span>
                                @endif
                            </div>

                            @if ($vacancy->closes_at)
                                <p class="role-close">
                                    Applications close
                                    <strong>{{ $vacancy->closes_at->timezone(config('recruitment.timezone'))->format('j F Y') }}</strong>
                                </p>
                            @endif
                        </div>

                        <a href="{{ route('careers.show', $vacancy->slug) }}" class="btn btn-primary">
                            See the role
                        </a>
                    </article>
                @endforeach
            </div>
        @endif

        <div class="note">
            <strong>We never charge to apply.</strong> There is no application fee, no training fee and no
            payment of any kind at any stage of our hiring. Anyone asking you for money on our behalf is
            not acting for us. You can read how we handle your information in our
            <a href="{{ route('careers.privacy') }}" style="color: var(--brand-dark); font-weight: 600;">recruitment privacy notice</a>.
        </div>

    </div>
</section>

@endsection
