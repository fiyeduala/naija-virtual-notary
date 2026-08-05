@extends('layouts.public', [
    'title' => 'About Us — Naija Virtual Notary',
    'description' => 'Learn about Naija Virtual Notary — Nigeria\'s online notarization platform serving the country and the diaspora.',
])

@push('styles')
<style>
    .page-hero {
        background: linear-gradient(135deg, #0f1a0b 0%, #1a3011 60%, #2a5020 100%);
        color: #fff; padding: 80px 24px 72px; text-align: center;
    }
    .page-hero h1 { font-size: clamp(32px, 4vw, 52px); font-weight: 800; color: #fff; margin-bottom: 16px; }
    .page-hero p { font-size: 18px; color: rgba(255,255,255,.8); max-width: 580px; margin: 0 auto; line-height: 1.6; }

    .about-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 64px; align-items: center; }
    @media (max-width: 720px) { .about-grid { grid-template-columns: 1fr; gap: 32px; } }

    .about-img-placeholder {
        background: var(--brand-light); border-radius: 16px; aspect-ratio: 4/3;
        display: flex; align-items: center; justify-content: center; font-size: 80px;
        border: 2px solid rgba(84,180,53,.2);
    }

    .missions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 24px; margin-top: 40px; }
    .mission-card {
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
        padding: 28px 24px;
    }
    .mission-card .icon { font-size: 28px; margin-bottom: 14px; }
    .mission-card h3 { font-size: 17px; font-weight: 600; margin-bottom: 8px; }
    .mission-card p { font-size: 14px; color: var(--muted); line-height: 1.65; }

    .team-section { background: var(--brand-light); }
    .team-text { max-width: 640px; }
    .team-text p { font-size: 16px; color: var(--muted); line-height: 1.75; margin-bottom: 16px; }

    .stats-bar {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1px;
        background: var(--line); border: 1px solid var(--line); border-radius: var(--radius);
        overflow: hidden; margin-top: 40px;
    }
    .stat-item { background: var(--surface); padding: 28px 20px; text-align: center; }
    .stat-num { font-size: 36px; font-weight: 800; color: var(--brand); }
    .stat-label { font-size: 13px; color: var(--muted); margin-top: 4px; }

    .cta-strip { background: linear-gradient(135deg, #54B435, #3d8a27); color: #fff; text-align: center; padding: 72px 24px; }
    .cta-strip h2 { font-size: clamp(24px, 3vw, 38px); font-weight: 700; margin-bottom: 12px; color: #fff; }
    .cta-strip p { font-size: 16px; color: rgba(255,255,255,.85); margin-bottom: 32px; }
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
        <div class="about-grid">
            <div>
                <div class="section-label">Who We Are</div>
                <h2 class="section-title">Nigeria's Online Notarial Platform</h2>
                <p style="font-size:16px;color:var(--muted);line-height:1.75;margin-bottom:20px;">
                    Naija Virtual Notary is Nigeria's premier online notarization service, built to serve Nigerians at home and across the diaspora. We specialize in digitizing document authentication — eliminating the friction of physical appointments, long wait times, and geographic limitations.
                </p>
                <p style="font-size:16px;color:var(--muted);line-height:1.75;">
                    Our platform connects clients with licensed notaries public, legal professionals, and tech experts who validate documents under Nigerian jurisdiction and in compliance with international standards.
                </p>
            </div>
            <div class="about-img-placeholder"><x-heroicon-o-building-office style="width:80px;height:80px;display:inline-block;vertical-align:middle;"/></div>
        </div>
    </div>
</section>

{{-- Our mission --}}
<section class="section" style="background:var(--bg);padding-top:0;">
    <div class="container">
        <div class="center">
            <div class="section-label">Our Core Mission</div>
            <h2 class="section-title">Built on three pillars</h2>
        </div>
        <div class="missions-grid">
            <div class="mission-card">
                <div class="icon"><x-heroicon-o-globe-alt style="width:28px;height:28px;display:inline-block;vertical-align:middle;"/></div>
                <h3>Accessibility</h3>
                <p>Eliminate physical appointments, waiting periods, and geographic barriers. Whether you're in Lagos or London, notarization is always one click away.</p>
            </div>
            <div class="mission-card">
                <div class="icon"><x-heroicon-o-shield-check style="width:28px;height:28px;display:inline-block;vertical-align:middle;"/></div>
                <h3>Security &amp; Legitimacy</h3>
                <p>End-to-end encrypted infrastructure with full compliance to Nigerian legal frameworks. Every document we notarize is internationally recognized.</p>
            </div>
            <div class="mission-card">
                <div class="icon"><x-heroicon-o-bolt style="width:28px;height:28px;display:inline-block;vertical-align:middle;"/></div>
                <h3>Speed</h3>
                <p>Most documents are notarized within 24 hours. Many in as little as 30 minutes. We respect your time and deliver without compromising quality.</p>
            </div>
        </div>
    </div>
</section>

{{-- Team section --}}
<section class="section team-section">
    <div class="container">
        <div class="about-grid">
            <div class="team-text">
                <div class="section-label">Our Team</div>
                <h2 class="section-title">Licensed professionals you can trust</h2>
                <p>Every notarization on our platform is handled by licensed notaries public operating under Nigerian law. Our team includes legal professionals and document specialists with years of experience in cross-border document authentication.</p>
                <p>We understand the unique challenges faced by Nigerians in the diaspora — navigating embassy requirements, property transactions abroad, and international business paperwork. Our notaries are trained for all of it.</p>
            </div>
            <div>
                <div class="stats-bar">
                    <div class="stat-item">
                        <div class="stat-num">500+</div>
                        <div class="stat-label">Documents notarized</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-num">24hr</div>
                        <div class="stat-label">Average turnaround</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-num">2</div>
                        <div class="stat-label">Currencies supported</div>
                    </div>
                </div>
                <p style="font-size:13px;color:var(--muted);margin-top:12px;">We support payments in both NGN and USD — because our clients are everywhere.</p>
            </div>
        </div>
    </div>
</section>

{{-- CTA --}}
<section class="cta-strip">
    <h2>Let's get you started</h2>
    <p>Create a free account and notarize your first document today.</p>
    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
        <a href="{{ route('register') }}" class="btn btn-white btn-lg">Sign Up Free</a>
        <a href="{{ route('partner') }}" class="btn btn-outline btn-lg" style="color:#fff;border-color:#fff;">Become a Partner</a>
    </div>
</section>

@endsection
