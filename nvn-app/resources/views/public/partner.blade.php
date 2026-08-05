@extends('layouts.public', [
    'title' => 'Partner With Us — Naija Virtual Notary',
    'description' => 'Join our network of licensed notaries. Earn through revenue sharing while we handle marketing and client acquisition.',
])

@push('styles')
<style>
    /* Hero */
    .page-hero {
        background: linear-gradient(135deg, #0f1a0b, #1a3011 60%, #2a5020);
        color: #fff; padding: 80px 24px 72px; text-align: center;
    }
    .page-hero h1 { font-size: clamp(32px, 4vw, 52px); font-weight: 800; color: #fff; margin-bottom: 16px; }
    .page-hero p { font-size: 18px; color: rgba(255,255,255,.8); max-width: 580px; margin: 0 auto; line-height: 1.6; }
    .page-hero .btns { display: flex; gap: 14px; justify-content: center; margin-top: 28px; flex-wrap: wrap; }

    /* Benefits */
    .benefits-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-top: 44px; }
    .benefit-card {
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
        padding: 28px 24px; transition: box-shadow .2s, transform .2s;
    }
    .benefit-card:hover { box-shadow: 0 4px 24px rgba(0,0,0,.07); transform: translateY(-2px); }
    .benefit-icon {
        width: 48px; height: 48px; background: var(--brand-light); border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        color: var(--brand-dark); margin-bottom: 16px;
    }
    .benefit-card h3 { font-size: 17px; font-weight: 600; margin-bottom: 8px; }
    .benefit-card p { font-size: 14px; color: var(--muted); line-height: 1.65; }

    /* Process */
    .process-section { background: var(--brand-light); }
    .process-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; margin-top: 44px; }
    .process-card {
        background: var(--surface); border-radius: var(--radius); padding: 28px 22px;
        border: 1px solid rgba(84,180,53,.2);
    }
    .process-num {
        width: 40px; height: 40px; background: var(--brand); color: #fff;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-size: 16px; font-weight: 800; margin-bottom: 16px;
    }
    .process-card h3 { font-size: 16px; font-weight: 600; margin-bottom: 8px; }
    .process-card p { font-size: 13px; color: var(--muted); line-height: 1.65; }

    /* Revenue model callout */
    .revenue-callout {
        background: linear-gradient(135deg, #54B435, #3d8a27); color: #fff;
        border-radius: var(--radius); padding: 40px 36px; text-align: center; margin-top: 48px;
    }
    .revenue-callout h3 { font-size: 22px; font-weight: 700; color: #fff; margin-bottom: 12px; }
    .revenue-callout p { font-size: 16px; color: rgba(255,255,255,.9); max-width: 480px; margin: 0 auto 24px; line-height: 1.65; }
    .revenue-split {
        display: flex; gap: 0; max-width: 360px; margin: 0 auto; border-radius: 10px; overflow: hidden;
        border: 2px solid rgba(255,255,255,.3);
    }
    .split-half { flex: 1; padding: 20px 10px; text-align: center; }
    .split-half:first-child { background: rgba(255,255,255,.15); }
    .split-half:last-child { background: rgba(255,255,255,.25); }
    .split-pct { font-size: 36px; font-weight: 800; color: #fff; }
    .split-label { font-size: 12px; color: rgba(255,255,255,.85); margin-top: 4px; text-transform: uppercase; letter-spacing: .06em; }

    /* Apply form section */
    .apply-section { padding: 80px 24px; background: var(--bg); }
    .apply-grid { display: grid; grid-template-columns: 1fr 1.4fr; gap: 56px; align-items: start; max-width: 1060px; margin: 0 auto; }
    @media (max-width: 800px) { .apply-grid { grid-template-columns: 1fr; gap: 32px; } }

    .apply-sidebar h2 { font-size: 26px; font-weight: 700; margin-bottom: 14px; }
    .apply-sidebar p { font-size: 15px; color: var(--muted); line-height: 1.7; margin-bottom: 16px; }
    .checklist { list-style: none; padding: 0; border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; }
    .checklist li {
        padding: 12px 16px; font-size: 14px; display: flex; gap: 10px; align-items: center;
        border-bottom: 1px solid var(--line); color: var(--ink);
    }
    .checklist li:last-child { border-bottom: none; }
    .check-icon { width: 18px; height: 18px; color: var(--brand); flex-shrink: 0; }

    /* Form card */
    .apply-form-card {
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
        padding: 36px;
    }
    .apply-form-card h2 { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
    .apply-form-card .sub { font-size: 14px; color: var(--muted); margin-bottom: 24px; }

    .form-section-title {
        font-size: 15px; font-weight: 600; color: var(--brand-dark);
        border-bottom: 2px solid var(--brand-light); padding-bottom: 8px;
        margin: 28px 0 16px;
    }
    .form-section-title:first-of-type { margin-top: 0; }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    @media (max-width: 560px) { .form-row { grid-template-columns: 1fr; } }

    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--ink); }
    .form-group label .req { color: var(--danger); margin-left: 2px; }
    .form-group label .hint { font-weight: 400; color: var(--muted); font-size: 12px; }
    .form-group input[type=text],
    .form-group input[type=email],
    .form-group input[type=tel],
    .form-group input[type=number],
    .form-group input[type=password],
    .form-group select,
    .form-group textarea {
        width: 100%; padding: 11px 13px; border: 1px solid var(--line);
        border-radius: 9px; font-family: var(--font); font-size: 15px; background: #fff;
        transition: border-color .15s, box-shadow .15s;
    }
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none; border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(84,180,53,.12);
    }
    .form-group input[type=file] {
        width: 100%; padding: 9px 12px; border: 1px dashed var(--line);
        border-radius: 9px; font-size: 14px; background: var(--brand-light);
        cursor: pointer;
    }
    .form-group textarea { min-height: 80px; resize: vertical; }

    .specialty-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 6px; }
    .specialty-grid label {
        display: flex; align-items: center; gap: 8px; font-weight: 400; font-size: 14px;
        padding: 8px 10px; border: 1px solid var(--line); border-radius: 7px;
        cursor: pointer; transition: background .15s, border-color .15s;
    }
    .specialty-grid label:hover { background: var(--brand-light); border-color: var(--brand); }
    .specialty-grid input[type=checkbox] { width: auto; accent-color: var(--brand); }

    .consent-group { margin-top: 10px; }
    .consent-item {
        display: flex; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--line);
        font-size: 13px; color: var(--muted); align-items: flex-start;
    }
    .consent-item:last-child { border-bottom: none; }
    .consent-item input[type=checkbox] { width: auto; margin-top: 1px; accent-color: var(--brand); flex-shrink: 0; }

    .submit-btn {
        width: 100%; margin-top: 20px; padding: 15px; background: var(--brand);
        color: #fff; border: none; border-radius: 10px; font-family: var(--font);
        font-size: 16px; font-weight: 700; cursor: pointer; transition: background .15s;
    }
    .submit-btn:hover { background: var(--brand-dark); }
    .form-note { font-size: 12px; color: var(--muted); text-align: center; margin-top: 12px; }

    .alert-error-box {
        background: #fdecec; border: 1px solid #f5c2c2; color: #a12626;
        border-radius: 9px; padding: 12px 16px; font-size: 13px; margin-bottom: 16px;
    }
    .alert-error-box ul { margin: 0; padding-left: 18px; }
</style>
@endpush

@section('content')

{{-- Hero --}}
<section class="page-hero">
    <h1>Partner With Naija Virtual Notary</h1>
    <p>Join Nigeria's leading online notarization platform. Earn through every completed notarization while we handle marketing, clients, and infrastructure.</p>
    <div class="btns">
        <a href="#apply" class="btn btn-primary btn-lg">Apply Now</a>
        <a href="{{ route('how-it-works') }}" class="btn btn-white btn-lg">How It Works</a>
    </div>
</section>

{{-- Benefits --}}
<section class="section">
    <div class="container">
        <div class="center">
            <div class="section-label">Why Join Us</div>
            <h2 class="section-title">Everything you need to grow your practice</h2>
            <p class="section-sub">We give you the platform, the clients, and the tools. You bring your licence and expertise.</p>
        </div>
        <div class="benefits-grid">
            <div class="benefit-card">
                <div class="benefit-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/></svg>
                </div>
                <h3>Revenue Sharing</h3>
                <p>Every completed notarization fee is split 50/50. More notarizations means more income — paid directly to your registered bank account with no manual invoicing.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px;"><path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 1 8.835-2.535m0 0A23.74 23.74 0 0 1 18.795 3c1.372 0 2.7.058 4.02.172"/></svg>
                </div>
                <h3>We Market for You</h3>
                <p>Active campaigns attract clients to the platform continuously. Your profile is promoted to both local Nigerian users and the global diaspora without any marketing spend from you.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0H3"/></svg>
                </div>
                <h3>Secure Digital Infrastructure</h3>
                <p>Access professional-grade encrypted document handling, video identity verification, digital seal placement, and PDF stamping — all included in your partnership.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                </div>
                <h3>Wider Client Reach</h3>
                <p>Go beyond your personal network. Serve clients from across Nigeria and the diaspora — UK, US, Canada, and beyond — through our growing platform.</p>
            </div>
        </div>

        {{-- Revenue split callout --}}
        <div class="revenue-callout">
            <h3>Transparent Revenue Sharing</h3>
            <p>Every notarization fee is split equally. No hidden deductions, no delayed payments. You keep half of every job you complete on the platform.</p>
            <div class="revenue-split">
                <div class="split-half">
                    <div class="split-pct">50%</div>
                    <div class="split-label">Your earnings</div>
                </div>
                <div class="split-half">
                    <div class="split-pct">50%</div>
                    <div class="split-label">Platform fee</div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- How it works for partners --}}
<section class="section process-section">
    <div class="container">
        <div class="center">
            <div class="section-label">The Process</div>
            <h2 class="section-title">How the partnership works</h2>
            <p class="section-sub">From registration to your first paid notarization in four straightforward steps.</p>
        </div>
        <div class="process-grid">
            <div class="process-card">
                <div class="process-num">1</div>
                <h3>Register &amp; Submit Credentials</h3>
                <p>Complete the application form below. Upload your valid ID, oath of office, and enter your notary licence reference and Supreme Court Number (SCN). An onboarding fee of ₦30,000 applies.</p>
            </div>
            <div class="process-card">
                <div class="process-num">2</div>
                <h3>Admin Review &amp; Approval</h3>
                <p>Our team reviews your credentials within 48 hours. Once approved, you'll receive an email link to complete your profile: upload your e-signature, official stamp, and seal.</p>
            </div>
            <div class="process-card">
                <div class="process-num">3</div>
                <h3>Go Live in the Marketplace</h3>
                <p>Set your service types and prices in both Naira and USD. Add your bank details for payouts. Once your profile is complete, you go live and clients can find and book you.</p>
            </div>
            <div class="process-card">
                <div class="process-num">4</div>
                <h3>Get Paid Per Notarization</h3>
                <p>Accept requests, join verification calls, notarize documents, and earn your 50% share automatically. Every completed job credits your account — no chasing payments.</p>
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
            <h2>Apply as a Notary Partner</h2>
            <p>This form creates your partner account. After submitting you'll verify your email, pay the onboarding fee, and our team will review your credentials.</p>
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
                    Credit/debit card for ₦30,000 onboarding fee
                </li>
            </ul>
            <p style="margin-top:16px;">After approval you will upload your e-signature, official stamp, and seal through a secure link sent to your email.</p>
        </div>

        {{-- Form --}}
        <div>
            <div class="apply-form-card">
                <h2>Create your partner account</h2>
                <p class="sub">Takes about 5–10 minutes. All fields marked <span style="color:var(--danger);">*</span> are required.</p>

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
                    <p class="form-note">After submitting you will verify your email and pay a one-time ₦30,000 onboarding fee. Your profile and seal uploads happen after admin approval.</p>
                </form>
            </div>
        </div>
    </div>
</section>

<script>
document.getElementById('entity_type').addEventListener('change', function () {
    document.getElementById('org-row').style.display = this.value === 'agency' ? '' : 'none';
});
</script>
@endsection
