@extends('layouts.public')

@push('styles')
<style>
    /* ───────── Hero ───────── */
    .hero {
        position: relative; overflow: hidden;
        background: linear-gradient(145deg, #0f1a0b 0%, #16250f 45%, #27491d 100%);
        color: #fff; padding: 88px 24px 96px;
    }
    .hero::before {
        content: ''; position: absolute; inset: 0; pointer-events: none;
        background:
            radial-gradient(44% 56% at 78% 18%, rgba(84,180,53,.30), transparent 62%),
            radial-gradient(38% 48% at 8% 86%, rgba(228,245,163,.13), transparent 60%);
    }
    .hero-inner {
        position: relative; z-index: 1;
        max-width: var(--maxw); margin: 0 auto;
        display: grid; grid-template-columns: 1.05fr .95fr; gap: 64px; align-items: center;
    }

    .hero-badge {
        display: inline-flex; align-items: center; gap: 9px;
        background: rgba(228,245,163,.12); border: 1px solid rgba(228,245,163,.3);
        color: var(--lemon); padding: 8px 16px 8px 11px; border-radius: 999px;
        font-size: 13px; font-weight: 600; margin-bottom: 26px;
    }
    .hero-badge .dot {
        width: 7px; height: 7px; border-radius: 50%; background: var(--lemon);
        box-shadow: 0 0 0 4px rgba(228,245,163,.18);
    }
    .hero h1 {
        font-size: clamp(38px, 5.4vw, 66px); font-weight: 800; line-height: 1.04; letter-spacing: -.022em;
        color: #fff; margin-bottom: 22px;
    }
    .hero h1 span {
        display: block;
        background: linear-gradient(100deg, var(--lemon) 10%, var(--brand) 95%);
        -webkit-background-clip: text; background-clip: text;
        -webkit-text-fill-color: transparent; color: var(--lemon);
    }
    .hero-lead {
        font-size: 18.5px; line-height: 1.66; color: rgba(255,255,255,.74);
        max-width: 480px; font-weight: 300; margin-bottom: 36px;
    }
    .hero-btns { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 34px; }
    .hero-foot {
        display: flex; align-items: center; gap: 10px;
        font-size: 13.5px; color: rgba(255,255,255,.58); font-weight: 300;
    }
    .hero-foot svg { color: var(--lemon); flex-shrink: 0; }

    /* Hero photograph, with the product's own milestones floating over it. */
    .hero-visual { position: relative; }
    .hero-photo {
        position: relative; border-radius: var(--r-xl); overflow: hidden;
        box-shadow: 0 32px 70px rgba(0,0,0,.42);
        border: 1px solid rgba(255,255,255,.1);
        aspect-ratio: 4 / 5;
    }
    .hero-photo img { width: 100%; height: 100%; object-fit: cover; object-position: 50% 28%; }
    .hero-photo::after {
        content: ''; position: absolute; inset: 0;
        background: linear-gradient(190deg, transparent 52%, rgba(15,26,11,.5));
    }
    .float-card {
        position: absolute; z-index: 2;
        display: flex; align-items: center; gap: 11px;
        background: rgba(255,255,255,.97);
        -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
        border-radius: 14px; padding: 12px 16px;
        box-shadow: 0 14px 34px rgba(0,0,0,.26);
        font-size: 13.5px; font-weight: 600; color: var(--ink);
        white-space: nowrap;
    }
    .float-card small { display: block; font-size: 11.5px; font-weight: 400; color: var(--muted); }
    .float-ico {
        width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
        display: grid; place-items: center; background: var(--brand-light); color: var(--brand-dark);
    }
    .float-1 { top: 11%;  left: -34px; animation: bob 7s ease-in-out infinite; }
    .float-2 { bottom: 24%; right: -30px; animation: bob 7s ease-in-out .9s infinite; }
    .float-3 { bottom: 5%; left: -22px; animation: bob 7s ease-in-out 1.8s infinite; }
    .float-3 .float-ico { background: var(--lemon); color: var(--lemon-ink); }
    @keyframes bob { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-7px); } }
    @media (prefers-reduced-motion: reduce) { .float-card { animation: none; } }

    @media (max-width: 980px) {
        .hero-inner { grid-template-columns: 1fr; gap: 48px; }
        .hero-lead { max-width: none; }
        .hero-visual { max-width: 420px; margin: 0 auto; width: 100%; }
        .float-1 { left: -14px; }
        .float-2 { right: -12px; }
        .float-3 { left: -8px; }
    }
    @media (max-width: 560px) {
        .hero { padding: 58px 20px 72px; }
        .hero-btns .btn { width: 100%; }
        .hero-photo { aspect-ratio: 1 / 1; }
        .float-card { padding: 10px 13px; font-size: 12.5px; }
        .float-1 { left: -8px; top: 8%; }
        .float-2 { right: -8px; }
        .float-3 { display: none; }
    }

    /* ───────── Trust bar ───────── */
    .trust-bar { background: var(--surface); border-bottom: 1px solid var(--line); }
    .trust-bar-inner {
        max-width: var(--maxw); margin: 0 auto; padding: 22px 24px;
        display: flex; flex-wrap: wrap; justify-content: space-between; gap: 18px 32px;
    }
    .trust-item {
        display: flex; align-items: center; gap: 9px;
        font-size: 14px; font-weight: 500; color: var(--ink-soft);
    }
    .trust-item svg { color: var(--brand); flex-shrink: 0; }
    @media (max-width: 860px) {
        .trust-bar-inner { justify-content: flex-start; }
        .trust-item { font-size: 13.5px; }
    }

    /* ───────── Why choose us — editorial, not a grid of identical cards ───────── */
    .why { background: var(--surface); }
    .why-grid {
        display: grid; grid-template-columns: .92fr 1.08fr; gap: 72px; align-items: center;
    }
    .why-visual { position: relative; isolation: isolate; }
    .why-photo {
        position: relative; z-index: 1;
        border-radius: var(--r-xl); overflow: hidden; box-shadow: var(--shadow-lg);
        aspect-ratio: 4 / 5;
    }
    .why-photo img { width: 100%; height: 100%; object-fit: cover; }
    /* Lemon slab offset behind the photograph. Stays at z-index 0 rather than
       -1, which would drop it behind the section's own white background. */
    .why-visual::before {
        content: ''; position: absolute; inset: 24px -24px -24px 24px; z-index: 0;
        border-radius: var(--r-xl); background: var(--lemon);
    }
    .why-stat {
        position: absolute; z-index: 2; right: -24px; bottom: 34px;
        background: #fff; border-radius: var(--r-lg); padding: 18px 22px;
        box-shadow: var(--shadow-lg); border: 1px solid var(--line-soft);
    }
    .why-stat b {
        display: block; font-family: var(--display); font-size: 26px;
        font-weight: 800; color: var(--brand-dark); line-height: 1;
    }
    .why-stat span { font-size: 12.5px; color: var(--muted); }

    .why-list { display: flex; flex-direction: column; margin-top: 34px; }
    .why-row {
        display: grid; grid-template-columns: 48px 1fr; gap: 20px;
        padding: 24px 0; border-top: 1px solid var(--line);
    }
    .why-row:last-child { border-bottom: 1px solid var(--line); }
    .why-ico {
        width: 48px; height: 48px; border-radius: 13px; display: grid; place-items: center;
        background: var(--brand-light); color: var(--brand-dark);
    }
    .why-row:nth-child(even) .why-ico { background: var(--lemon-soft); color: var(--lemon-ink); }
    .why-row h3 {
        font-size: 17.5px; font-weight: 700; margin-bottom: 6px; color: var(--ink);
        letter-spacing: -.01em;
    }
    .why-row p { font-size: 14.8px; color: var(--muted); line-height: 1.68; font-weight: 300; }

    @media (max-width: 980px) {
        .why-grid { grid-template-columns: 1fr; gap: 56px; }
        .why-visual { max-width: 400px; }
        .why-visual::before { inset: 18px -18px -18px 18px; }
        .why-stat { right: -12px; }
    }

    /* ───────── How it works ───────── */
    .steps-section { background: var(--lemon-soft); }
    .steps-grid {
        margin-top: 56px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 32px;
        position: relative;
    }
    /* The connector runs through the step numbers, behind the opaque cards,
       so it is only ever visible in the gaps between them. */
    .steps-grid::before {
        content: ''; position: absolute; top: 34px; left: 16%; right: 16%; height: 2px;
        background: repeating-linear-gradient(90deg, var(--lemon-deep) 0 9px, transparent 9px 18px);
        z-index: 0;
    }
    .step-item {
        position: relative; z-index: 1;
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--r-lg); overflow: hidden;
        box-shadow: var(--shadow-sm);
        transition: transform .22s, box-shadow .22s;
    }
    .step-item:hover { transform: translateY(-5px); box-shadow: var(--shadow-lg); }
    .step-media { position: relative; aspect-ratio: 3 / 2; overflow: hidden; background: var(--line-soft); }
    .step-media img { width: 100%; height: 100%; object-fit: cover; }
    .step-num {
        position: absolute; top: 14px; left: 14px; z-index: 2;
        width: 40px; height: 40px; border-radius: 12px;
        background: var(--brand); color: #fff;
        display: grid; place-items: center;
        font-family: var(--display); font-size: 17px; font-weight: 800;
        box-shadow: 0 6px 18px rgba(15,26,11,.3);
    }
    .step-body { padding: 24px 24px 28px; }
    .step-body h3 { font-size: 18px; font-weight: 700; margin-bottom: 9px; color: var(--ink); }
    .step-body p { font-size: 14.8px; color: var(--muted); line-height: 1.68; font-weight: 300; }

    @media (max-width: 900px) {
        .steps-grid { grid-template-columns: 1fr; gap: 24px; max-width: 460px; margin-inline: auto; }
        .steps-grid::before { display: none; }
    }

    /* ───────── Testimonials ───────── */
    .tst-grid { margin-top: 52px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 26px; }
    .tst-card {
        position: relative; background: var(--surface);
        border: 1px solid var(--line); border-radius: var(--r-lg);
        padding: 34px 30px 28px; display: flex; flex-direction: column;
        transition: border-color .2s, box-shadow .2s;
    }
    .tst-card:hover { border-color: var(--lemon-deep); box-shadow: var(--shadow); }
    .tst-mark {
        font-family: var(--display); font-size: 62px; font-weight: 800; line-height: .7;
        color: var(--lemon); margin-bottom: 10px;
    }
    .tst-card blockquote {
        font-size: 16px; line-height: 1.68; color: var(--ink-soft);
        font-weight: 300; margin-bottom: 24px; flex: 1;
    }
    .tst-by { display: flex; align-items: center; gap: 12px; padding-top: 18px; border-top: 1px solid var(--line); }
    /* Initials, not a face: we have no photograph of any of these people and
       a stock portrait here would read as a claim we cannot make. */
    .tst-initials {
        width: 42px; height: 42px; border-radius: 50%; flex-shrink: 0;
        display: grid; place-items: center;
        background: var(--brand-light); color: var(--brand-dark);
        font-family: var(--display); font-weight: 700; font-size: 14.5px;
    }
    .tst-name { font-size: 14.5px; font-weight: 600; color: var(--ink); }
    .tst-place { font-size: 12.8px; color: var(--faint); }
    .stars { color: #e8a317; font-size: 13.5px; letter-spacing: 2px; margin-bottom: 14px; }

    @media (max-width: 900px) { .tst-grid { grid-template-columns: 1fr; max-width: 520px; margin-inline: auto; } }

    /* ───────── Closing CTA ───────── */
    .cta-strip { padding: 96px 24px; }
    .cta-actions { display: flex; flex-wrap: wrap; gap: 14px; justify-content: center; }
    @media (max-width: 560px) { .cta-actions .btn { width: 100%; } }
</style>
@endpush

@section('content')

{{-- ───────── Hero ───────── --}}
<section class="hero">
    <div class="hero-inner">
        <div>
            <div class="hero-badge"><span class="dot"></span> Nigeria's #1 Online Notary Platform</div>
            <h1>Notarize Documents <span>Anytime, Anywhere</span></h1>
            <p class="hero-lead">Bringing notarization to your fingertips. Fast, secure, and 100% online — no queues, no travel.</p>
            <div class="hero-btns">
                <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Request Notarization</a>
                <a href="{{ route('how-it-works') }}" class="btn btn-ghost-light btn-lg">How It Works</a>
            </div>
            <div class="hero-foot">
                <x-heroicon-o-shield-check style="width:17px;height:17px;"/>
                Every document is sealed by a notary licensed under Nigerian law
            </div>
        </div>

        <div class="hero-visual">
            <div class="hero-photo">
                <img src="{{ asset('images/site/hero-video-notarization.webp') }}"
                     alt="A woman at her desk speaking with a notary over a video call on her laptop"
                     width="1000" height="1500" fetchpriority="high" decoding="async">
            </div>
            <div class="float-card float-1">
                <span class="float-ico"><x-heroicon-o-arrow-up-tray style="width:17px;height:17px;"/></span>
                <span>Document uploaded<small>Encrypted in transit</small></span>
            </div>
            <div class="float-card float-2">
                <span class="float-ico"><x-heroicon-o-video-camera style="width:17px;height:17px;"/></span>
                <span>Identity verified<small>Live with your notary</small></span>
            </div>
            <div class="float-card float-3">
                <span class="float-ico"><x-heroicon-o-check-badge style="width:17px;height:17px;"/></span>
                <span>Sealed &amp; ready<small>Download from your dashboard</small></span>
            </div>
        </div>
    </div>
</section>

{{-- ───────── Trust bar ───────── --}}
<div class="trust-bar">
    <div class="trust-bar-inner">
        <div class="trust-item"><x-heroicon-o-lock-closed style="width:18px;height:18px;"/> Encrypted uploads</div>
        <div class="trust-item"><x-heroicon-o-bolt style="width:18px;height:18px;"/> Notarized in 30 minutes</div>
        <div class="trust-item"><x-heroicon-o-globe-alt style="width:18px;height:18px;"/> Nigeria + Diaspora</div>
        <div class="trust-item"><x-heroicon-o-credit-card style="width:18px;height:18px;"/> Pay in Naira or USD</div>
        <div class="trust-item"><x-heroicon-o-academic-cap style="width:18px;height:18px;"/> Licensed notaries</div>
    </div>
</div>

{{-- ───────── Why choose us ───────── --}}
<section class="section why">
    <div class="container">
        <div class="why-grid">
            <div class="why-visual" data-reveal>
                <div class="why-photo">
                    <img src="{{ asset('images/site/lagos-boardroom.webp') }}"
                         alt="Colleagues reviewing paperwork together around a table in a Lagos office"
                         width="1400" height="933" loading="lazy" decoding="async">
                </div>
                <div class="why-stat">
                    <b>100%</b>
                    <span>Online, start to finish</span>
                </div>
            </div>

            <div data-reveal data-reveal-delay="90">
                <div class="section-label">Why Choose Us</div>
                <h2 class="section-title">Everything you need.<br>Nothing you don't.</h2>
                <p class="section-sub">We've reimagined the notarization process to save you time, money, and stress.</p>

                <div class="why-list">
                    <div class="why-row">
                        <span class="why-ico"><x-heroicon-o-clock style="width:24px;height:24px;"/></span>
                        <div>
                            <h3>Time saving</h3>
                            <p>No waiting in long queues or travelling to an office. Complete your notarization from home in minutes.</p>
                        </div>
                    </div>
                    <div class="why-row">
                        <span class="why-ico"><x-heroicon-o-shield-check style="width:24px;height:24px;"/></span>
                        <div>
                            <h3>Secure platform</h3>
                            <p>Documents are encrypted in transit and at rest, and stored on a private disk that is not reachable from the web. Only you and your assigned notary can open them.</p>
                        </div>
                    </div>
                    <div class="why-row">
                        <span class="why-ico"><x-heroicon-o-moon style="width:24px;height:24px;"/></span>
                        <div>
                            <h3>Always open</h3>
                            <p>Submit a request at any hour — day or night. Your notary picks it up and you are notified the moment it is sealed.</p>
                        </div>
                    </div>
                    <div class="why-row">
                        <span class="why-ico"><x-heroicon-o-building-office style="width:24px;height:24px;"/></span>
                        <div>
                            <h3>Properly sealed</h3>
                            <p>Our notaries are licensed under Nigerian law, and their notarizations are recognized both in Nigeria and internationally. Every finished document carries the notary's official seal, and we record a SHA-256 fingerprint of the sealed file, so the platform holds a tamper-evident record of exactly what was issued.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ───────── How it works ───────── --}}
<section class="section steps-section">
    <div class="container">
        <div class="center">
            <div class="section-label">Simple Process</div>
            <h2 class="section-title">Three steps to a notarized document</h2>
        </div>
        <div class="steps-grid">
            <div class="step-item" data-reveal>
                <div class="step-media">
                    <span class="step-num">1</span>
                    <img src="{{ asset('images/site/step-upload-documents.webp') }}"
                         alt="Paperwork and a laptop on a desk, seen from above"
                         width="900" height="600" loading="lazy" decoding="async">
                </div>
                <div class="step-body">
                    <h3>Upload your document</h3>
                    <p>Submit your file securely through our encrypted online portal. Any legal, business, or personal document.</p>
                </div>
            </div>
            <div class="step-item" data-reveal data-reveal-delay="110">
                <div class="step-media">
                    <span class="step-num">2</span>
                    <img src="{{ asset('images/site/step-video-verification.webp') }}"
                         alt="A client holding her documents during a video call with a notary"
                         width="900" height="1350" loading="lazy" decoding="async">
                </div>
                <div class="step-body">
                    <h3>Verify online</h3>
                    <p>Connect via video call with a licensed notary to confirm your identity and witness your signature — no in-person visit needed.</p>
                </div>
            </div>
            <div class="step-item" data-reveal data-reveal-delay="220">
                <div class="step-media">
                    <span class="step-num">3</span>
                    <img src="{{ asset('images/site/step-notary-seal.webp') }}"
                         alt="A notary applying a stamp to a document at his desk"
                         width="900" height="601" loading="lazy" decoding="async">
                </div>
                <div class="step-body">
                    <h3>Download instantly</h3>
                    <p>Once notarized, your signed and sealed document is ready for immediate download. Physical copies available on request.</p>
                </div>
            </div>
        </div>
        <div style="text-align:center;margin-top:48px;">
            <a href="{{ route('how-it-works') }}" class="btn btn-primary">See the full process</a>
        </div>
    </div>
</section>

{{-- ───────── Testimonials ───────── --}}
<section class="section">
    <div class="container">
        <div class="center">
            <div class="section-label">Testimonials</div>
            <h2 class="section-title">Trusted by Nigerians worldwide</h2>
        </div>
        <div class="tst-grid">
            <div class="tst-card" data-reveal>
                <div class="tst-mark">&ldquo;</div>
                <div class="stars">★★★★★</div>
                <blockquote>Incredibly fast and professional. I notarized my documents from London in under an hour. Highly recommend!</blockquote>
                <div class="tst-by">
                    <span class="tst-initials">AN</span>
                    <span>
                        <span class="tst-name">Adaora N.</span>
                        <span class="tst-place">London, UK</span>
                    </span>
                </div>
            </div>
            <div class="tst-card" data-reveal data-reveal-delay="100">
                <div class="tst-mark">&ldquo;</div>
                <div class="stars">★★★★★</div>
                <blockquote>The video call process was smooth and the notary was very thorough. Documents were accepted without any issues.</blockquote>
                <div class="tst-by">
                    <span class="tst-initials">CO</span>
                    <span>
                        <span class="tst-name">Chukwuemeka O.</span>
                        <span class="tst-place">Lagos</span>
                    </span>
                </div>
            </div>
            <div class="tst-card" data-reveal data-reveal-delay="200">
                <div class="tst-mark">&ldquo;</div>
                <div class="stars">★★★★★</div>
                <blockquote>I was skeptical about online notarization but this platform completely changed my mind. Secure, fast, and affordable.</blockquote>
                <div class="tst-by">
                    <span class="tst-initials">FB</span>
                    <span>
                        <span class="tst-name">Fatimah B.</span>
                        <span class="tst-place">Abuja</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ───────── CTA ───────── --}}
<section class="cta-strip">
    <h2>Ready to get started?</h2>
    <p>Join thousands of Nigerians at home and in the diaspora. Create your free account and upload your first document in minutes — from anywhere in the world.</p>
    <div class="cta-actions">
        <a href="{{ route('register') }}" class="btn btn-white btn-lg">Create a free account</a>
        <a href="{{ route('how-it-works') }}" class="btn btn-ghost-light btn-lg">See how it works</a>
    </div>
</section>

@endsection
