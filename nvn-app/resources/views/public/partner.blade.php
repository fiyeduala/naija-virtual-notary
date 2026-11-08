@extends('layouts.public', [
    'title' => 'Partner With Us — Naija Virtual Notary',
    'description' => 'Join our network of licensed notaries. Earn through revenue sharing while we handle marketing and client acquisition.',
])

@php
    /*
    | Read, never typed. The fee and the split are the same expressions the
    | checkout and the payout use — PlatformSetting for the fee (as in
    | OnboardingFeeController and NotaryGuideController::feeDisplay), and
    | Settings::defaultCommissionRate for the platform's share. A page that
    | quotes a price the checkout does not charge is worse than no page.
    |
    | commission_rate is the PLATFORM's cut: NotaryProfile::payoutMinor pays
    | the notary (100 - commission_rate), so the notary's share is derived
    | rather than assumed to be the other half of a number we guessed.
    */
    $partnerFee     = '₦' . number_format(((int) \App\Models\PlatformSetting::get('onboarding_fee_ngn', config('nvn.onboarding_fee_ngn'))) / 100, 0);
    $platformShare  = (int) \App\Support\Settings::defaultCommissionRate();
    $notaryShare    = 100 - $platformShare;
@endphp

@push('styles')
<style>
    /*
    | page-hero and cta-strip come from layouts/public. Everything below is
    | specific to this page.
    |
    | The application form itself is untouched — every input, name, id, @csrf
    | and the entity_type script are exactly as they were. Only the shell
    | around it is restyled, so nothing about submission can have changed.
    */

    .page-hero .btns { display: flex; gap: 14px; justify-content: center; margin-top: 30px; flex-wrap: wrap; }
    .hero-aside {
        font-size: 14.5px; color: rgba(255,255,255,.72);
        max-width: 560px; margin: 34px auto 0; line-height: 1.65;
    }
    .hero-aside a { color: #fff; font-weight: 600; text-decoration: underline; text-underline-offset: 3px; }

    /* ---- Intro split ---- */
    .pt-split { display: grid; grid-template-columns: 1fr 1fr; gap: 68px; align-items: center; }
    .pt-copy p { font-size: 16.5px; color: var(--muted); line-height: 1.78; margin: 0 0 18px; }
    .pt-copy p:last-child { margin-bottom: 0; }
    .pt-visual { position: relative; isolation: isolate; }
    .pt-visual::before {
        content: ''; position: absolute; z-index: 0; inset: 24px -24px -24px 24px;
        border-radius: var(--r-xl); background: var(--lemon); opacity: .5;
    }
    .pt-visual.is-flipped::before { inset: 24px 24px -24px -24px; }
    .pt-photo {
        position: relative; z-index: 1; display: block; width: 100%; height: auto;
        border-radius: var(--r-xl); box-shadow: var(--shadow-lg);
    }

    /* ---- Benefits: a ruled list, not four identical boxes ---- */
    .ben-list { margin-top: 52px; display: grid; grid-template-columns: 1fr 1fr; gap: 0 56px; }
    .ben {
        display: grid; grid-template-columns: 46px 1fr; gap: 20px;
        padding: 30px 0; border-top: 1px solid var(--line);
    }
    .ben-ic {
        width: 46px; height: 46px; border-radius: 13px; background: var(--brand-light);
        color: var(--brand-dark); display: flex; align-items: center; justify-content: center;
    }
    .ben h3 {
        font-family: var(--display); font-size: 18px; font-weight: 700;
        color: var(--ink); margin: 0 0 8px; letter-spacing: -.01em;
    }
    .ben p { font-size: 15px; color: var(--muted); line-height: 1.72; margin: 0; }

    /* ---- Revenue split ---- */
    .rev { background: var(--forest); color: #fff; }
    .rev .section-title { color: #fff; }
    .rev .section-sub   { color: rgba(255,255,255,.66); }
    .rev .section-label { color: var(--lemon); }
    .rev .section-label::before { background: var(--lemon); }

    .rev-wrap { max-width: 720px; margin: 48px auto 0; }
    /* One bar, split by the real rate. A pie or a pair of big percentages says
       less than showing the two shares against each other at actual width. */
    .rev-bar {
        display: flex; height: 92px; border-radius: var(--radius); overflow: hidden;
        border: 1px solid rgba(255,255,255,.18);
    }
    .rev-seg { display: flex; flex-direction: column; justify-content: center; padding: 0 24px; }
    .rev-seg.is-yours { background: var(--lemon); color: var(--forest); }
    .rev-seg.is-ours  { background: rgba(255,255,255,.07); color: rgba(255,255,255,.8); }
    .rev-pct { font-family: var(--display); font-size: 29px; font-weight: 800; letter-spacing: -.02em; line-height: 1; }
    .rev-lab { font-size: 12.5px; letter-spacing: .07em; text-transform: uppercase; margin-top: 7px; font-weight: 600; }
    .rev-notes { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-top: 30px; }
    .rev-note {
        border-top: 1px solid rgba(255,255,255,.16); padding-top: 16px;
        font-size: 14.5px; line-height: 1.7; color: rgba(255,255,255,.7);
    }
    .rev-note strong { color: #fff; font-weight: 600; display: block; margin-bottom: 4px; }

    /* ---- Process ---- */
    .process-section { background: var(--lemon-soft); }
    .process-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 22px; margin-top: 48px; }
    .process-card {
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--r-lg); padding: 28px 24px;
    }
    .process-num {
        width: 38px; height: 38px; background: var(--brand); color: #fff;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-family: var(--display); font-size: 15px; font-weight: 800; margin-bottom: 18px;
    }
    .process-card h3 {
        font-family: var(--display); font-size: 16.5px; font-weight: 700;
        color: var(--ink); margin: 0 0 9px; letter-spacing: -.01em;
    }
    .process-card p { font-size: 14px; color: var(--muted); line-height: 1.7; margin: 0; }

    /* ---- Apply ---- */
    .apply-section { padding: 96px 24px; background: var(--bg); border-top: 1px solid var(--line-soft); }
    .apply-grid {
        display: grid; grid-template-columns: 1fr 1.45fr; gap: 56px;
        align-items: start; max-width: 1090px; margin: 0 auto;
    }
    .apply-sidebar { position: sticky; top: calc(var(--nav-h) + 26px); }
    .apply-sidebar h2 {
        font-family: var(--display); font-size: 27px; font-weight: 800;
        letter-spacing: -.02em; color: var(--ink); margin: 0 0 14px;
    }
    .apply-sidebar p { font-size: 15px; color: var(--muted); line-height: 1.72; margin: 0 0 16px; }
    .checklist {
        list-style: none; padding: 0; margin: 0;
        border: 1px solid var(--line); border-radius: var(--r-lg);
        overflow: hidden; background: var(--surface);
    }
    .checklist li {
        padding: 13px 16px; font-size: 14px; display: flex; gap: 11px; align-items: center;
        border-bottom: 1px solid var(--line-soft); color: var(--ink); line-height: 1.5;
    }
    .checklist li:last-child { border-bottom: none; }
    .check-icon { width: 18px; height: 18px; color: var(--brand); flex-shrink: 0; }

    .apply-form-card {
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--r-xl); padding: 40px; box-shadow: var(--shadow-sm);
    }
    .apply-form-card h2 {
        font-family: var(--display); font-size: 23px; font-weight: 800;
        letter-spacing: -.02em; color: var(--ink); margin: 0 0 6px;
    }
    .apply-form-card .sub { font-size: 14px; color: var(--muted); margin: 0 0 28px; }

    .form-section-title {
        font-family: var(--display); font-size: 13px; font-weight: 700;
        letter-spacing: .09em; text-transform: uppercase; color: var(--brand-dark);
        border-bottom: 1px solid var(--line); padding-bottom: 10px; margin: 34px 0 18px;
    }
    .form-section-title:first-of-type { margin-top: 0; }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13.5px; font-weight: 600; margin-bottom: 7px; color: var(--ink); }
    .form-group label .req { color: #c0392b; margin-left: 2px; }
    .form-group label .hint { font-weight: 400; color: var(--muted); font-size: 12px; }
    .form-group input[type=text],
    .form-group input[type=email],
    .form-group input[type=tel],
    .form-group input[type=number],
    .form-group input[type=password],
    .form-group select,
    .form-group textarea {
        width: 100%; padding: 12px 14px; border: 1px solid var(--line);
        border-radius: var(--r-sm); font-family: var(--font); font-size: 15px;
        color: var(--ink); background: #fff;
        transition: border-color .15s, box-shadow .15s;
    }
    .form-group input::placeholder,
    .form-group textarea::placeholder { color: var(--faint); }
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none; border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(84,180,53,.15);
    }
    .form-group input[type=file] {
        width: 100%; padding: 13px 14px; border: 1px dashed var(--lemon-deep);
        border-radius: var(--r-sm); font-size: 13.5px; background: var(--lemon-soft);
        cursor: pointer; color: var(--ink-soft);
    }
    .form-group input[type=file]:hover { border-color: var(--brand); }
    .form-group textarea { min-height: 92px; resize: vertical; line-height: 1.6; }

    .specialty-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 8px; }
    .specialty-grid label {
        display: flex; align-items: center; gap: 9px; font-weight: 500; font-size: 14px;
        padding: 10px 12px; border: 1px solid var(--line); border-radius: var(--r-sm);
        cursor: pointer; margin: 0; transition: background .15s, border-color .15s;
    }
    .specialty-grid label:hover { background: var(--lemon-soft); border-color: var(--lemon-deep); }
    .specialty-grid input[type=checkbox] { width: 18px; height: 18px; accent-color: var(--brand); flex-shrink: 0; }

    .consent-group { margin-top: 12px; }
    .consent-item {
        display: flex; gap: 11px; padding: 14px 0; border-bottom: 1px solid var(--line-soft);
        font-size: 13.5px; color: var(--muted); align-items: flex-start; line-height: 1.65;
    }
    .consent-item:last-child { border-bottom: none; }
    .consent-item input[type=checkbox] { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--brand); flex-shrink: 0; }

    .submit-btn {
        width: 100%; margin-top: 24px; padding: 16px; background: var(--brand);
        color: #fff; border: none; border-radius: 999px; font-family: var(--display);
        font-size: 16px; font-weight: 700; cursor: pointer;
        transition: background .15s, transform .15s, box-shadow .15s;
    }
    .submit-btn:hover { background: var(--brand-dark); transform: translateY(-1px); box-shadow: var(--shadow); }
    .submit-btn:active { transform: none; }
    .form-note { font-size: 12.5px; color: var(--muted); text-align: center; margin-top: 14px; line-height: 1.65; }

    .alert-error-box {
        background: #fdecec; border: 1px solid #f5c2c2; color: #a12626;
        border-radius: var(--r-sm); padding: 14px 18px; font-size: 13.5px; margin-bottom: 18px;
    }
    .alert-error-box ul { margin: 0; padding-left: 18px; }

    @media (max-width: 980px) {
        .process-grid { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 900px) {
        .pt-split { grid-template-columns: 1fr; gap: 44px; }
        .pt-visual::before,
        .pt-visual.is-flipped::before { inset: 18px -14px -18px 14px; }
        .ben-list { grid-template-columns: 1fr; gap: 0; margin-top: 36px; }
        .rev-notes { grid-template-columns: 1fr; gap: 16px; }
        .apply-grid { grid-template-columns: 1fr; gap: 34px; }
        .apply-sidebar { position: static; }
        .apply-section { padding: 68px 20px; }
        .apply-form-card { padding: 26px 20px; }
    }
    @media (max-width: 620px) {
        .process-grid { grid-template-columns: 1fr; }
        .specialty-grid { grid-template-columns: 1fr; }
        .rev-bar { height: auto; flex-direction: column; }
        .rev-seg { padding: 20px 22px; }
    }
    @media (max-width: 560px) {
        .form-row { grid-template-columns: 1fr; }
    }
</style>
@endpush

@section('content')

{{-- Hero --}}
<section class="page-hero">
    <h1>Partner With Naija Virtual Notary</h1>
    <p>Join our network of licensed notaries. Earn a share of every notarization you complete, while we handle the clients, the marketing and the infrastructure.</p>
    <div class="btns">
        <a href="#apply" class="btn btn-primary btn-lg">Apply Now</a>
        <a href="{{ route('how-it-works') }}" class="btn btn-ghost-light btn-lg">How It Works</a>
    </div>
    {{-- This page is for notaries. A body that wants to send us work is a
         different arrangement entirely, and this was the only door marked
         "partner" — so people arrived here for the wrong one. --}}
    <p class="hero-aside">
        Are you a government body, embassy, law firm or company wanting to send us
        notarization work?
        <a href="{{ route('organization.apply.show') }}">Apply as an organization instead</a>.
    </p>
</section>

{{-- Intro --}}
<section class="section">
    <div class="container">
        <div class="pt-split" data-reveal>
            <div class="pt-copy">
                <div class="section-label">Why Join Us</div>
                <h2 class="section-title">Your licence on our platform</h2>
                <p>
                    You already have the licence, the seal and the authority. What takes the time is
                    finding the clients, taking the payment, and keeping a record of every job you have
                    done. That is the part we run.
                </p>
                <p>
                    Clients come to the platform, see your profile and your prices, and book you
                    directly. You accept the request, meet them on a video call, and apply your own
                    signature, stamp and seal. Your name stays on the document throughout.
                </p>
            </div>
            <div class="pt-visual">
                {{-- Illustrative stock photography. See public/images/site/CREDITS.md —
                     nobody pictured here is a Naija Virtual Notary notary, client or
                     member of staff. --}}
                <img class="pt-photo"
                     src="{{ asset('images/site/partner-collaboration.webp') }}"
                     width="1200" height="800" loading="lazy" decoding="async"
                     alt="Three professionals working together around a laptop.">
            </div>
        </div>

        <div class="ben-list">
            <div class="ben" data-reveal>
                <div class="ben-ic"><x-heroicon-o-banknotes style="width:23px;height:23px;"/></div>
                <div>
                    <h3>A share of every job</h3>
                    <p>You keep {{ $notaryShare }}% of the fee on each notarization you complete. Your share is recorded against your account as each job finishes and paid out to the bank details on your profile — you never raise an invoice for it.</p>
                </div>
            </div>
            <div class="ben" data-reveal>
                <div class="ben-ic"><x-heroicon-o-megaphone style="width:23px;height:23px;"/></div>
                <div>
                    <h3>We bring the clients</h3>
                    <p>We run the campaigns and the platform that bring people here, in Nigeria and across the diaspora. Your listing is in front of them without any marketing spend of your own.</p>
                </div>
            </div>
            <div class="ben" data-reveal>
                <div class="ben-ic"><x-heroicon-o-computer-desktop style="width:23px;height:23px;"/></div>
                <div>
                    <h3>The tooling, built for you</h3>
                    <p>Encrypted document handling, video identity sessions, seal and signature placement, and PDF stamping are all part of the platform. There is nothing for you to buy or install.</p>
                </div>
            </div>
            <div class="ben" data-reveal>
                <div class="ben-ic"><x-heroicon-o-globe-alt style="width:23px;height:23px;"/></div>
                <div>
                    <h3>Reach past your own network</h3>
                    <p>Take work from clients anywhere in Nigeria and from Nigerians abroad — the UK, the US, Canada and beyond — not only the people who can reach your office.</p>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- Revenue split --}}
<section class="section rev">
    <div class="container">
        <div class="center">
            <div class="section-label">The Arrangement</div>
            <h2 class="section-title">How the money is split</h2>
            <p class="section-sub">One rate, applied to every completed job. No hidden deductions.</p>
        </div>
        <div class="rev-wrap" data-reveal>
            <div class="rev-bar">
                <div class="rev-seg is-yours" style="flex: {{ $notaryShare }};">
                    <div class="rev-pct">{{ $notaryShare }}%</div>
                    <div class="rev-lab">You keep</div>
                </div>
                <div class="rev-seg is-ours" style="flex: {{ $platformShare }};">
                    <div class="rev-pct">{{ $platformShare }}%</div>
                    <div class="rev-lab">Platform</div>
                </div>
            </div>
            <div class="rev-notes">
                <div class="rev-note">
                    <strong>What the platform share covers</strong>
                    Client acquisition, payment processing, the video and sealing tools, hosting and support.
                </div>
                <div class="rev-note">
                    <strong>Joining costs {{ $partnerFee }} a year</strong>
                    A membership fee that covers your first year on the platform, paid after you verify your email.
                </div>
            </div>
        </div>
    </div>
</section>

{{-- Who we are looking for --}}
<section class="section">
    <div class="container">
        <div class="pt-split" data-reveal>
            <div class="pt-visual is-flipped" style="order:-1;">
                {{-- Illustrative stock photography. See public/images/site/CREDITS.md —
                     nobody pictured here is one of our notaries. --}}
                <img class="pt-photo"
                     src="{{ asset('images/site/partner-handshake.webp') }}"
                     width="1200" height="800" loading="lazy" decoding="async"
                     alt="Two professionals shaking hands across a desk.">
            </div>
            <div class="pt-copy">
                <div class="section-label">Who We Are Looking For</div>
                <h2 class="section-title">The requirements are short</h2>
                <p>
                    You must be a notary public licensed under Nigerian law and in good standing,
                    able to produce your oath of office and your Supreme Court Number. That is the
                    part we verify before you are listed.
                </p>
                <p>
                    Beyond that, the work needs a quiet room, a reliable internet connection and a
                    camera good enough to see a client's ID clearly on a video call. If you can run
                    a video meeting, you can run a verification session on this platform.
                </p>
            </div>
        </div>
    </div>
</section>

{{-- Process --}}
<section class="section process-section">
    <div class="container">
        <div class="center">
            <div class="section-label">The Process</div>
            <h2 class="section-title">How the partnership works</h2>
            <p class="section-sub">From application to your first paid notarization, in four steps.</p>
        </div>
        <div class="process-grid" data-reveal>
            <div class="process-card">
                <div class="process-num">1</div>
                <h3>Apply with your credentials</h3>
                <p>Complete the form below. Upload a valid ID and your oath of office, and give your notary licence reference and Supreme Court Number. The membership fee of {{ $partnerFee }} covers your first year.</p>
            </div>
            <div class="process-card">
                <div class="process-num">2</div>
                <h3>We review and approve</h3>
                <p>We aim to review credentials within 48 hours. Once approved you get an email link to complete your profile — your e-signature, official stamp and seal.</p>
            </div>
            <div class="process-card">
                <div class="process-num">3</div>
                <h3>Go live in the marketplace</h3>
                <p>Set the services you offer and your own price for each, and add the bank account you want to be paid into. We review your completed profile before listing you publicly.</p>
            </div>
            <div class="process-card">
                <div class="process-num">4</div>
                <h3>Take jobs and get paid</h3>
                <p>Accept requests, join the verification call, seal the document. Your {{ $notaryShare }}% of each completed job is added to what you are owed and settled to your bank account.</p>
            </div>
        </div>
    </div>
</section>

{{-- Application form --}}
<section class="apply-section" id="apply">
    <div class="apply-grid">
        {{-- Sidebar --}}
        <div class="apply-sidebar">
            <div class="section-label">Get Started</div>
            <h2>Apply as a notary partner</h2>
            <p>This form creates your partner account. After submitting you will verify your email, pay the membership fee, and we will review your credentials.</p>
            <p>Have these ready before you start:</p>
            <ul class="checklist">
                <li>
                    <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    Government-issued ID (PDF or image)
                </li>
                <li>
                    <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    Notary oath of office document
                </li>
                <li>
                    <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    Notary licence / appointment ref. number
                </li>
                <li>
                    <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    Supreme Court Number (SCN)
                </li>
                <li>
                    <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    Year you took your oath of office
                </li>
                <li>
                    <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    Card or bank transfer for the {{ $partnerFee }} membership fee (one year)
                </li>
            </ul>
            <p style="margin-top:18px;">After approval you will upload your e-signature, official stamp and seal through a secure link sent to your email.</p>
        </div>

        {{-- Form --}}
        <div>
            <div class="apply-form-card">
                <h2>Create your partner account</h2>
                <p class="sub">Takes about 5–10 minutes. All fields marked <span style="color:#c0392b;">*</span> are required.</p>

                @if (session('status'))
                    <div style="background:var(--brand-light);border:1px solid var(--brand);color:var(--brand-dark);border-radius:9px;padding:12px 16px;font-size:14px;margin-bottom:16px;">
                        {{ session('status') }}
                    </div>
                @endif
                @if ($errors->any())
                    <div class="alert-error-box">
                        <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('partner') }}" enctype="multipart/form-data">
                    @csrf

                    <div class="form-section-title">Personal details</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="full_name">Full name <span class="req">*</span></label>
                            <input id="full_name" type="text" name="full_name" value="{{ old('full_name') }}" required autocomplete="name">
                        </div>
                        <div class="form-group">
                            <label for="email">Email address <span class="req">*</span></label>
                            <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone">Phone number <span class="req">*</span></label>
                            <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" required autocomplete="tel">
                        </div>
                        <div class="form-group">
                            <label for="entity_type">Type <span class="req">*</span></label>
                            <select id="entity_type" name="entity_type" required>
                                <option value="individual" @selected(old('entity_type','individual')==='individual')>Individual Notary</option>
                                <option value="agency" @selected(old('entity_type')==='agency')>Notary Agency</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" id="org-row" style="{{ old('entity_type')==='agency' ? '' : 'display:none;' }}">
                        <label for="organization_name">Organisation name <span class="req">*</span> <span class="hint">(agencies only)</span></label>
                        <input id="organization_name" type="text" name="organization_name" value="{{ old('organization_name') }}">
                    </div>

                    <div class="form-section-title">Login credentials</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="password">Password <span class="req">*</span> <span class="hint">(min 8 chars, mixed case + number)</span></label>
                            <input id="password" type="password" name="password" required autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label for="password_confirmation">Confirm password <span class="req">*</span></label>
                            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                        </div>
                    </div>

                    <div class="form-section-title">Notary credentials</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="license_ref">Licence / appointment ref. <span class="req">*</span></label>
                            <input id="license_ref" type="text" name="license_ref" value="{{ old('license_ref') }}" required>
                        </div>
                        <div class="form-group">
                            <label for="scn">Supreme Court Number (SCN) <span class="req">*</span></label>
                            <input id="scn" type="text" name="scn" value="{{ old('scn') }}" required placeholder="e.g. SCN/1234/2019">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="year_of_oath">Year of oath taking <span class="req">*</span></label>
                            <input id="year_of_oath" type="number" name="year_of_oath" value="{{ old('year_of_oath') }}" min="1950" max="{{ date('Y') }}" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="experience">Experience &amp; nature of documents notarized <span class="req">*</span></label>
                        <textarea id="experience" name="experience" required placeholder="Describe your years of practice and the types of documents you commonly notarize.">{{ old('experience') }}</textarea>
                    </div>

                    <div class="form-group">
                        <label>Specialties <span class="req">*</span> <span class="hint">(select all that apply)</span></label>
                        <div class="specialty-grid">
                            @foreach (\App\Enums\Specialty::cases() as $s)
                                <label>
                                    <input type="checkbox" name="specialties[]" value="{{ $s->value }}"
                                        @checked(in_array($s->value, old('specialties', [])))>
                                    {{ $s->label() }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="motivation">Why do you want to partner with us? <span class="req">*</span></label>
                        <textarea id="motivation" name="motivation" required placeholder="Tell us why you want to join the platform and what you hope to achieve.">{{ old('motivation') }}</textarea>
                    </div>

                    <div class="form-section-title">Document uploads</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="valid_id">Valid ID document <span class="req">*</span> <span class="hint">(PDF, JPG, PNG — max 10 MB)</span></label>
                            <input id="valid_id" type="file" name="valid_id" accept=".pdf,.jpg,.jpeg,.png" required>
                        </div>
                        <div class="form-group">
                            <label for="oath_of_office">Oath of office <span class="req">*</span> <span class="hint">(PDF, JPG, PNG — max 10 MB)</span></label>
                            <input id="oath_of_office" type="file" name="oath_of_office" accept=".pdf,.jpg,.jpeg,.png" required>
                        </div>
                    </div>

                    <div class="form-section-title">Consents</div>
                    <div class="consent-group">
                        <div class="consent-item">
                            <input type="checkbox" id="accuracy_consent" name="accuracy_consent" value="1">
                            <label for="accuracy_consent" style="font-weight:400;margin:0;cursor:pointer;">I confirm that all information provided is accurate and I consent to credential verification by Naija Virtual Notary.</label>
                        </div>
                        <div class="consent-item">
                            <input type="checkbox" id="commission_consent" name="commission_consent" value="1">
                            <label for="commission_consent" style="font-weight:400;margin:0;cursor:pointer;">I agree that Naija Virtual Notary retains 50% of each notarization fee as a platform commission, and I receive the remaining 50%.</label>
                        </div>
                        <div class="consent-item">
                            <input type="checkbox" id="delegation_consent" name="delegation_consent" value="1">
                            <label for="delegation_consent" style="font-weight:400;margin:0;cursor:pointer;">I authorise Naija Virtual Notary to complete a paid request on my behalf — applying my signature, stamp and seal — where I have not completed it myself, so that clients are not left waiting. My name remains on the document and I am credited at my agreed rate.</label>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn">Submit application &rarr;</button>
                    <p class="form-note">After submitting you will verify your email and pay the {{ $partnerFee }} partner fee, which covers your first year. Your profile and seal uploads happen after admin approval.</p>
                </form>
            </div>
        </div>
    </div>
</section>

{{-- CTA --}}
<section class="cta-strip">
    <h2>Ready to take your first job?</h2>
    <p>Apply with your credentials — we aim to review them within 48 hours.</p>
    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
        <a href="#apply" class="btn btn-white btn-lg">Apply Now</a>
        <a href="{{ route('how-it-works') }}" class="btn btn-ghost-light btn-lg">See How It Works</a>
    </div>
</section>

<script>
document.getElementById('entity_type').addEventListener('change', function () {
    document.getElementById('org-row').style.display = this.value === 'agency' ? '' : 'none';
});
</script>
@endsection
