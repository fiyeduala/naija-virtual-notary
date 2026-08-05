@extends('layouts.public', ['title' => "Naija's #1 Online Notary Service — Naija Virtual Notary"])

@push('styles')
<style>
    /* Hero */
    .hero {
        background: linear-gradient(135deg, #0f1a0b 0%, #1a3011 55%, #2a5020 100%);
        color: #fff; padding: 100px 24px 96px; text-align: center; position: relative; overflow: hidden;
    }
    .hero::before {
        content: ''; position: absolute; inset: 0;
        background: radial-gradient(ellipse 80% 60% at 50% 0%, rgba(84,180,53,.25) 0%, transparent 70%);
    }
    .hero-inner { position: relative; max-width: 780px; margin: 0 auto; }
    .hero-badge {
        display: inline-block; background: rgba(84,180,53,.2); border: 1px solid rgba(84,180,53,.4);
        color: #a8e88a; border-radius: 999px; padding: 6px 18px; font-size: 13px; font-weight: 600;
        margin-bottom: 24px; letter-spacing: .04em;
    }
    .hero h1 {
        font-size: clamp(36px, 5.5vw, 60px); font-weight: 800; line-height: 1.1;
        margin-bottom: 20px; color: #fff;
    }
    .hero h1 span { color: #78d44a; }
    .hero p {
        font-size: clamp(16px, 2vw, 20px); color: rgba(255,255,255,.8);
        line-height: 1.6; margin-bottom: 40px; max-width: 580px; margin-left: auto; margin-right: auto;
    }
    .hero-btns { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }

    /* Trust bar */
    .trust-bar { background: var(--brand-light); border-top: 1px solid rgba(84,180,53,.2); border-bottom: 1px solid rgba(84,180,53,.2); padding: 20px 24px; }
    .trust-bar-inner { max-width: 1160px; margin: 0 auto; display: flex; gap: 32px; justify-content: center; flex-wrap: wrap; align-items: center; }
    .trust-item { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: var(--brand-dark); }
    .trust-icon { color: var(--brand); }

    /* Why choose us */
    .features-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-top: 48px; }
    .feature-card {
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
        padding: 28px; transition: box-shadow .2s, transform .2s;
    }
    .feature-card:hover { box-shadow: var(--shadow); transform: translateY(-2px); }
    .feature-icon {
        width: 52px; height: 52px; background: var(--brand-light); border-radius: 12px;
        display: flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 16px;
    }
    .feature-card h3 { font-size: 17px; font-weight: 600; margin-bottom: 8px; color: var(--ink); }
    .feature-card p { font-size: 14px; color: var(--muted); line-height: 1.6; }

    /* Steps */
    .steps-section { background: var(--brand-light); }
    .steps-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0; margin-top: 48px; position: relative; }
    .steps-grid::before {
        content: ''; position: absolute; top: 32px; left: 10%; right: 10%; height: 2px;
        background: repeating-linear-gradient(90deg, var(--brand) 0, var(--brand) 10px, transparent 10px, transparent 20px);
    }
    .step-item { text-align: center; padding: 0 16px; position: relative; z-index: 1; }
    .step-num {
        width: 64px; height: 64px; background: var(--brand); color: #fff;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-size: 22px; font-weight: 700; margin: 0 auto 20px;
        box-shadow: 0 0 0 6px var(--brand-light);
    }
    .step-item h3 { font-size: 16px; font-weight: 600; margin-bottom: 8px; }
    .step-item p { font-size: 13px; color: var(--muted); line-height: 1.6; }

    /* Testimonials */
    .testimonials-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; margin-top: 48px; }
    .testimonial-card {
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 28px;
    }
    .stars { color: #f5a623; font-size: 16px; margin-bottom: 14px; }
    .testimonial-card blockquote { font-size: 15px; color: var(--ink); line-height: 1.65; margin: 0 0 16px; font-style: italic; }
    .testimonial-author { font-size: 13px; font-weight: 600; color: var(--brand-dark); }

    /* CTA strip */
    .cta-strip {
        background: linear-gradient(135deg, #54B435, #3d8a27);
        color: #fff; text-align: center; padding: 80px 24px;
    }
    .cta-strip h2 { font-size: clamp(26px, 3.5vw, 42px); font-weight: 700; margin-bottom: 16px; color: #fff; }
    .cta-strip p { font-size: 17px; color: rgba(255,255,255,.85); margin-bottom: 36px; }
    .cta-strip .btn-white { font-size: 16px; padding: 15px 36px; }

    @media (max-width: 640px) {
        .steps-grid::before { display: none; }
    }
</style>
@endpush

@section('content')

{{-- Hero --}}
<section class="hero">
    <div class="hero-inner">
        <div class="hero-badge">Nigeria's #1 Online Notary Platform</div>
        <h1>Notarize Documents <span>Anytime, Anywhere</span></h1>
        <p>Bringing notarization to your fingertips. Fast, secure, and 100% online — no queues, no travel.</p>
        <div class="hero-btns">
            <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Request Notarization</a>
            <a href="{{ route('how-it-works') }}" class="btn btn-white btn-lg">How It Works</a>
        </div>
    </div>
</section>

{{-- Trust bar --}}
<div class="trust-bar">
    <div class="trust-bar-inner">
        <div class="trust-item"><span class="trust-icon"><x-heroicon-o-lock-closed style="width:18px;height:18px;display:inline-block;vertical-align:middle;"/></span> Encrypted &amp; Secure</div>
        <div class="trust-item"><span class="trust-icon"><x-heroicon-o-bolt style="width:18px;height:18px;display:inline-block;vertical-align:middle;"/></span> Same-day turnaround</div>
        <div class="trust-item"><span class="trust-icon"><x-heroicon-o-globe-alt style="width:18px;height:18px;display:inline-block;vertical-align:middle;"/></span> Nigeria + Diaspora</div>
        <div class="trust-item"><span class="trust-icon"><x-heroicon-o-credit-card style="width:18px;height:18px;display:inline-block;vertical-align:middle;"/></span> NGN &amp; USD accepted</div>
        <div class="trust-item"><span class="trust-icon"><x-heroicon-o-academic-cap style="width:18px;height:18px;display:inline-block;vertical-align:middle;"/></span> Licensed notaries</div>
    </div>
</div>

{{-- Why choose us --}}
<section class="section">
    <div class="container">
        <div class="center">
            <div class="section-label">Why Choose Us</div>
            <h2 class="section-title">Everything you need. Nothing you don't.</h2>
            <p class="section-sub">We've reimagined the notarization process to save you time, money, and stress.</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon"><x-heroicon-o-clock style="width:32px;height:32px;display:inline-block;vertical-align:middle;"/></div>
                <h3>Time Saving</h3>
                <p>No waiting in long queues or travelling to an office. Complete your notarization from home in minutes.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><x-heroicon-o-shield-check style="width:32px;height:32px;display:inline-block;vertical-align:middle;"/></div>
                <h3>Secure Platform</h3>
                <p>End-to-end encrypted document handling. Your documents and identity are protected at every step.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><x-heroicon-o-moon style="width:32px;height:32px;display:inline-block;vertical-align:middle;"/></div>
                <h3>24/7 Availability</h3>
                <p>Our platform is always open. Submit requests any time — day or night — and notarize without leaving home.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><x-heroicon-o-building-office style="width:32px;height:32px;display:inline-block;vertical-align:middle;"/></div>
                <h3>Legally Recognised</h3>
                <p>Our licensed notaries operate under Nigerian law. Documents are recognized domestically and internationally.</p>
            </div>
        </div>
    </div>
</section>

{{-- How it works --}}
<section class="section steps-section">
    <div class="container">
        <div class="center">
            <div class="section-label">Simple Process</div>
            <h2 class="section-title">Three steps to a notarized document</h2>
        </div>
        <div class="steps-grid">
            <div class="step-item">
                <div class="step-num">1</div>
                <h3>Upload Your Document</h3>
                <p>Submit your file securely through our encrypted online portal. Any legal, business, or personal document.</p>
            </div>
            <div class="step-item">
                <div class="step-num">2</div>
                <h3>Verify Online</h3>
                <p>Connect via video call with a licensed notary to confirm your identity and witness your signature — no in-person visit needed.</p>
            </div>
            <div class="step-item">
                <div class="step-num">3</div>
                <h3>Download Instantly</h3>
                <p>Once notarized, your signed and sealed document is ready for immediate download. Physical copies available on request.</p>
            </div>
        </div>
        <div style="text-align:center;margin-top:44px;">
            <a href="{{ route('how-it-works') }}" class="btn btn-primary">See the full process</a>
        </div>
    </div>
</section>

{{-- Testimonials --}}
<section class="section">
    <div class="container">
        <div class="center">
            <div class="section-label">Testimonials</div>
            <h2 class="section-title">Trusted by Nigerians worldwide</h2>
        </div>
        <div class="testimonials-grid">
            <div class="testimonial-card">
                <div class="stars">★★★★★</div>
                <blockquote>"Incredibly fast and professional. I notarized my documents from London in under an hour. Highly recommend!"</blockquote>
                <div class="testimonial-author">Adaora N. — London, UK</div>
            </div>
            <div class="testimonial-card">
                <div class="stars">★★★★★</div>
                <blockquote>"The video call process was smooth and the notary was very thorough. Documents were accepted without any issues."</blockquote>
                <div class="testimonial-author">Chukwuemeka O. — Lagos</div>
            </div>
            <div class="testimonial-card">
                <div class="stars">★★★★★</div>
                <blockquote>"I was skeptical about online notarization but this platform completely changed my mind. Secure, fast, and affordable."</blockquote>
                <div class="testimonial-author">Fatimah B. — Abuja</div>
            </div>
        </div>
    </div>
</section>

{{-- CTA --}}
<section class="cta-strip">
    <h2>Ready to get started?</h2>
    <p>Join thousands of Nigerians who've already notarized their documents online.</p>
    <a href="{{ route('register') }}" class="btn btn-white btn-lg">Create a free account</a>
</section>

@endsection
