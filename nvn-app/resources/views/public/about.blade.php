@extends('layouts.public', [
    'title' => 'About Us — Naija Virtual Notary',
    'description' => 'Learn about Naija Virtual Notary — Nigeria\'s online notarization platform serving the country and the diaspora.',
])

@push('styles')
<style>
    /*
    | page-hero and cta-strip now come from layouts/public, so this file styles
    | only what is specific to About. Pushed styles land after the layout style
    | block, so anything re-declared here would quietly win — nothing here is.
    */

    /* ---- Shared split: copy one side, photograph the other ---- */
    .ab-split {
        display: grid; grid-template-columns: 1.05fr .95fr; gap: 72px; align-items: center;
    }
    .ab-split.is-reversed .ab-copy { order: 2; }
    .ab-copy p {
        font-size: 16.5px; color: var(--muted); line-height: 1.78; margin: 0 0 18px;
    }
    .ab-copy p:last-child { margin-bottom: 0; }

    /* The photograph sits on an offset lemon slab. isolation keeps the slab
       above the section's own white ground instead of behind it. */
    .ab-visual { position: relative; isolation: isolate; }
    .ab-visual::before {
        content: ''; position: absolute; z-index: 0;
        inset: 26px -26px -26px 26px; border-radius: var(--r-xl);
        background: var(--lemon); opacity: .5;
    }
    .ab-visual.is-flipped::before { inset: 26px 26px -26px -26px; }
    .ab-photo {
        position: relative; z-index: 1; display: block; width: 100%; height: auto;
        border-radius: var(--r-xl); box-shadow: var(--shadow-lg);
    }

    /* ---- Mission statement ---- */
    .mission-band { background: var(--lemon-soft); border-top: 1px solid var(--line-soft); border-bottom: 1px solid var(--line-soft); }
    .mission-quote {
        max-width: 860px; margin: 0 auto; text-align: center;
        font-family: var(--display); font-weight: 700;
        font-size: clamp(22px, 3.1vw, 34px); line-height: 1.34;
        letter-spacing: -.018em; color: var(--ink);
    }
    .mission-quote em { font-style: normal; color: var(--brand-dark); }
    .mission-by {
        margin-top: 22px; text-align: center; font-size: 13.5px;
        letter-spacing: .08em; text-transform: uppercase; color: var(--lemon-ink); font-weight: 600;
    }

    /* ---- Three pillars, on the forest ground so they break the white rhythm ---- */
    .pillars { background: var(--forest); color: #fff; }
    .pillars .section-title { color: #fff; }
    .pillars .section-sub   { color: rgba(255,255,255,.66); }
    .pillars .section-label { color: var(--lemon); }
    .pillars .section-label::before { background: var(--lemon); }
    .pillar-grid {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; margin-top: 54px;
    }
    .pillar {
        padding: 34px 34px 8px; border-top: 1px solid rgba(255,255,255,.16);
    }
    .pillar + .pillar { border-left: 1px solid rgba(255,255,255,.1); }
    .pillar-n {
        font-family: var(--display); font-size: 15px; font-weight: 700;
        color: var(--lemon); letter-spacing: .1em; margin-bottom: 20px;
    }
    .pillar h3 {
        font-family: var(--display); font-size: 21px; font-weight: 700;
        color: #fff; margin: 0 0 12px; letter-spacing: -.01em;
    }
    .pillar p { font-size: 15px; line-height: 1.75; color: rgba(255,255,255,.68); margin: 0; }

    /* ---- Figures ---- */
    .figures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; }
    .figure {
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--r-lg); padding: 30px 28px;
    }
    .figure-n {
        font-family: var(--display); font-weight: 800; letter-spacing: -.03em;
        font-size: clamp(30px, 3.4vw, 42px); line-height: 1; color: var(--brand-dark);
    }
    .figure-k {
        margin-top: 12px; font-size: 14.5px; font-weight: 600; color: var(--ink);
    }
    .figure-d { margin-top: 6px; font-size: 13.5px; line-height: 1.6; color: var(--muted); }
    .figures-note {
        margin-top: 22px; font-size: 13px; color: var(--muted); line-height: 1.7; max-width: 640px;
    }

    @media (max-width: 900px) {
        .ab-split { grid-template-columns: 1fr; gap: 44px; }
        .ab-split.is-reversed .ab-copy { order: 0; }
        .ab-visual::before,
        .ab-visual.is-flipped::before { inset: 18px -14px -18px 14px; }
        .pillar-grid { grid-template-columns: 1fr; margin-top: 38px; }
        .pillar + .pillar { border-left: 0; }
        .pillar { padding: 28px 0 10px; }
        .figures { grid-template-columns: 1fr; gap: 16px; }
    }
</style>
@endpush

@section('content')

<section class="page-hero">
    <h1>About Naija Virtual Notary</h1>
    <p>We're on a mission to make notarization accessible to every Nigerian, wherever they are in the world.</p>
</section>

{{-- Who we are --}}
<section class="section">
    <div class="container">
        <div class="ab-split" data-reveal>
            <div class="ab-copy">
                <div class="section-label">Who We Are</div>
                <h2 class="section-title">Nigeria's online notarial platform</h2>
                <p>
                    Naija Virtual Notary is an online notarization service built to serve Nigerians at home
                    and across the diaspora. We digitise document authentication — removing the physical
                    appointment, the waiting room, and the need to be in the same country as your notary.
                </p>
                <p>
                    The platform connects clients with licensed notaries public who verify identity over a
                    live video session and apply their own seal under Nigerian law. Nothing is automated
                    away: a named notary reviews your document and signs it.
                </p>
            </div>
            <div class="ab-visual">
                {{-- Stock photography. See public/images/site/CREDITS.md — nobody pictured
                     anywhere on this site is a Naija Virtual Notary client, notary or member
                     of staff, and no caption or alt text may suggest otherwise. --}}
                <img class="ab-photo"
                     src="{{ asset('images/site/about-team-lagos.webp') }}"
                     width="1400" height="933" loading="lazy" decoding="async"
                     alt="Two colleagues in conversation in a Lagos office.">
            </div>
        </div>
    </div>
</section>

{{-- Mission statement --}}
<section class="section section-sm mission-band">
    <div class="container">
        <p class="mission-quote" data-reveal>
            Getting a document notarized should not depend on <em>where you happen to be standing.</em>
            We exist so that a Nigerian in Abuja and a Nigerian in Atlanta reach the same notary,
            on the same day, with the same seal at the end of it.
        </p>
        <div class="mission-by">Our mission</div>
    </div>
</section>

{{-- Three pillars --}}
<section class="section pillars">
    <div class="container">
        <div class="center">
            <div class="section-label">Our Core Mission</div>
            <h2 class="section-title">Built on three pillars</h2>
        </div>
        <div class="pillar-grid" data-reveal>
            <div class="pillar">
                <div class="pillar-n">01</div>
                <h3>Accessibility</h3>
                <p>No physical appointment, no waiting period, no geographic barrier. Whether you are in
                   Lagos or London, the same notary is one sign-in away.</p>
            </div>
            <div class="pillar">
                <div class="pillar-n">02</div>
                <h3>Security &amp; Legitimacy</h3>
                <p>Documents are encrypted in transit and at rest, and stored on a private disk that is
                   not reachable from the web — open only to you and the notary assigned to your request.
                   Every notary on the platform is licensed under Nigerian law, and we record a SHA-256
                   fingerprint of each sealed file.</p>
            </div>
            <div class="pillar">
                <div class="pillar-n">03</div>
                <h3>Speed</h3>
                <p>Most documents are notarized in 30 minutes or less. If the notary you chose is not
                   available at that moment, it may take up to 24 hours.</p>
            </div>
        </div>
    </div>
</section>

{{-- Notary network --}}
<section class="section">
    <div class="container">
        <div class="ab-split is-reversed" data-reveal>
            <div class="ab-copy">
                <div class="section-label">Our Notary Network</div>
                <h2 class="section-title">Licensed professionals you can trust</h2>
                <p>
                    Every notarization on the platform is handled by a licensed notary public operating
                    under Nigerian law. Each one is reviewed and listed by us before a client can book
                    them, and each one applies their own signature, stamp and seal to your document.
                </p>
                <p>
                    We understand the problems Nigerians abroad run into — embassy requirements, property
                    transactions in another country, business paperwork that has to cross a border and
                    still be accepted at the other end. Our notarizations are recognized domestically and
                    internationally, and documents sealed on this platform are in use with institutions
                    and authorities outside Nigeria.
                </p>
                <p style="margin-top:26px;">
                    <a href="{{ route('partner') }}" class="btn btn-outline">Join as a notary</a>
                </p>
            </div>
            <div class="ab-visual is-flipped">
                {{-- Illustrative stock photography, not our team. See CREDITS.md. --}}
                <img class="ab-photo"
                     src="{{ asset('images/site/step-review-documents.webp') }}"
                     width="900" height="600" loading="lazy" decoding="async"
                     alt="A professional reviewing printed documents at a desk.">
            </div>
        </div>
    </div>
</section>

{{-- Figures --}}
<section class="section section-sm" style="background:var(--bg);">
    <div class="container">
        <div class="section-label">By The Numbers</div>
        <h2 class="section-title" style="margin-bottom:34px;">Where the service stands today</h2>
        <div class="figures" data-reveal>
            <div class="figure">
                <div class="figure-n">500+</div>
                <div class="figure-k">Documents notarized</div>
                <div class="figure-d">Sealed for clients across Nigeria and the diaspora since the platform opened.</div>
            </div>
            <div class="figure">
                <div class="figure-n">30min</div>
                <div class="figure-k">Typical turnaround</div>
                <div class="figure-d">Most documents are sealed within half an hour, and up to 24 hours if your chosen notary is unavailable.</div>
            </div>
            <div class="figure">
                <div class="figure-n">SHA-256</div>
                <div class="figure-k">Fingerprint on every seal</div>
                <div class="figure-d">We record a cryptographic fingerprint of each sealed file, so there is a tamper-evident record of exactly what was issued.</div>
            </div>
        </div>
        <p class="figures-note">
            We handle six categories of document — affidavits, property documents, contracts, travel
            consent letters, academic credentials and immigration documents. Turnaround describes typical
            service rather than a guarantee: it still depends on your notary's availability and on your
            identity verification being completed.
        </p>
    </div>
</section>

{{-- CTA --}}
<section class="cta-strip">
    <h2>Let's get you started</h2>
    <p>Create a free account and notarize your first document today.</p>
    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
        <a href="{{ route('register') }}" class="btn btn-white btn-lg">Sign Up Free</a>
        <a href="{{ route('partner') }}" class="btn btn-ghost-light btn-lg">Become a Partner</a>
    </div>
</section>

@endsection
