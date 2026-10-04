@extends('layouts.public', [
    'title' => $organization->name . ' — Notarize your documents | Naija Virtual Notary',
    'description' => 'Notarize documents for ' . $organization->name . ' online, with Nigeria\'s leading virtual notary service. Verified by a Nigerian notary public, sealed and returned the same day.',
])

{{--
    The page a partner body hands to its applicants.

    Its one job is to set the referral cookie and then get out of the way, so
    both buttons lead into the ordinary flow. Rebuilding registration or intake
    behind a second door would mean two versions of each to keep in step, and
    the second one would be the one that quietly rots.

    The body's commission is not on this page at any price. That is between the
    platform and the body, and an applicant who learned of it would reasonably
    wonder what they were paying for.
--}}

@push('styles')
<style>
    .org-hero {
        background: linear-gradient(135deg, #0f1a0b, #1a3011 60%, #2a5020);
        color: #fff; padding: 64px 24px 60px; text-align: center;
    }
    .org-hero .crest {
        display: inline-flex; align-items: center; justify-content: center;
        background: #fff; border-radius: 14px; padding: 12px 16px; margin-bottom: 22px;
        min-height: 72px;
    }
    .org-hero .crest img { max-height: 56px; width: auto; display: block; }
    .org-hero .eyebrow {
        font-size: 12.5px; letter-spacing: .1em; text-transform: uppercase;
        color: rgba(255,255,255,.55); margin-bottom: 10px;
    }
    .org-hero h1 { font-size: clamp(28px, 3.6vw, 44px); font-weight: 800; color: #fff; margin-bottom: 14px; }
    .org-hero p { font-size: 17px; color: rgba(255,255,255,.82); max-width: 620px; margin: 0 auto; line-height: 1.65; }
    .org-hero .btns { display: flex; gap: 14px; justify-content: center; margin-top: 30px; flex-wrap: wrap; }

    .org-signed {
        background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.22);
        border-radius: var(--radius); padding: 18px 22px; margin: 26px auto 0;
        max-width: 520px; font-size: 14.5px; color: rgba(255,255,255,.9); line-height: 1.6;
    }

    .org-steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 22px; margin-top: 40px; }
    .org-step { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 26px 22px; }
    .org-step .num {
        width: 36px; height: 36px; border-radius: 50%; background: var(--brand); color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 15px; font-weight: 800; margin-bottom: 14px;
    }
    .org-step h3 { font-size: 16px; font-weight: 600; margin-bottom: 7px; }
    .org-step p { font-size: 13.5px; color: var(--muted); line-height: 1.65; }

    .org-prices { background: var(--brand-light); }
    .org-price-list {
        max-width: 620px; margin: 36px auto 0; background: var(--surface);
        border: 1px solid rgba(84,180,53,.25); border-radius: var(--radius); overflow: hidden;
    }
    .org-price-row {
        display: flex; justify-content: space-between; align-items: center; gap: 16px;
        padding: 16px 22px; border-bottom: 1px solid var(--line);
    }
    .org-price-row:last-child { border-bottom: none; }
    .org-price-row .name { font-size: 14.5px; font-weight: 500; color: var(--ink); }
    .org-price-row .amount {
        font-size: 16px; font-weight: 700; color: var(--brand-dark);
        font-variant-numeric: tabular-nums; white-space: nowrap;
    }
    .org-price-note { max-width: 620px; margin: 16px auto 0; font-size: 13px; color: var(--muted); text-align: center; line-height: 1.65; }

    .org-cta { text-align: center; }
    .org-cta .btns { display: flex; gap: 14px; justify-content: center; margin-top: 26px; flex-wrap: wrap; }
</style>
@endpush

@section('content')

<section class="org-hero">
    @if ($organization->logo_url)
        <div class="crest">
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($organization->logo_url) }}"
                 alt="{{ $organization->name }}">
        </div>
    @endif

    <div class="eyebrow">In partnership with {{ config('app.name') }}</div>
    <h1>Notarize your documents for {{ $organization->name }}</h1>
    <p>
        Upload your document, meet a Nigerian notary public on a short video call,
        and get it back signed, sealed and stamped — without leaving your desk.
        Work that comes through {{ $organization->name }} is handled by our own
        notary public at {{ $organization->name }}'s agreed rate.
    </p>

    @auth
        <div class="org-signed">
            You are signed in as {{ auth()->user()->full_name }}. Start your notarization
            and it will be recorded against {{ $organization->name }}.
        </div>
        <div class="btns">
            <a href="{{ route('client.request.create') }}" class="btn btn-primary btn-lg">Start a notarization</a>
            <a href="{{ route('dashboard') }}" class="btn btn-white btn-lg">My dashboard</a>
        </div>
    @else
        <div class="btns">
            <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Create an account</a>
            <a href="{{ route('login') }}" class="btn btn-white btn-lg">I already have one</a>
        </div>
    @endauth
</section>

<section class="section">
    <div class="container">
        <div class="center">
            <div class="section-label">How it works</div>
            <h2 class="section-title">Four steps, start to finish</h2>
            <p class="section-sub">
                The whole thing happens online. You will need a valid ID and about
                fifteen minutes.
            </p>
        </div>

        <div class="org-steps">
            <div class="org-step">
                <div class="num">1</div>
                <h3>Create your account</h3>
                <p>Your name, email and phone number. We send a code to confirm the email is yours.</p>
            </div>
            <div class="org-step">
                <div class="num">2</div>
                <h3>Upload your document</h3>
                <p>PDF or Word. Tell us what kind of notarization you need and pay the fee shown below.</p>
            </div>
            <div class="org-step">
                <div class="num">3</div>
                <h3>Meet the notary</h3>
                <p>A short video call with our notary public, who checks your ID and witnesses your signature.</p>
            </div>
            <div class="org-step">
                <div class="num">4</div>
                <h3>Collect the sealed copy</h3>
                <p>The notarized document is signed, sealed, stamped and downloadable from your dashboard.</p>
            </div>
        </div>
    </div>
</section>

@if ($services->isNotEmpty())
<section class="section org-prices">
    <div class="container">
        <div class="center">
            <div class="section-label">Fees</div>
            <h2 class="section-title">{{ $organization->name }} rates</h2>
            <p class="section-sub">
                Per document, agreed with {{ $organization->name }}. Nothing else is added
                at checkout.
            </p>
        </div>

        <div class="org-price-list">
            @foreach ($services as $service)
                <div class="org-price-row">
                    <span class="name">{{ $service->service_type }}</span>
                    <span class="amount">{{ $quotes[$service->id] ?? '—' }}</span>
                </div>
            @endforeach
        </div>

        <p class="org-price-note">
            If you are notarizing more than one document, each is charged at the rate
            above — every document gets its own seal, signature and finished copy.
        </p>
    </div>
</section>
@endif

<section class="section org-cta">
    <div class="container">
        <h2 class="section-title">Ready when you are</h2>
        <p class="section-sub" style="margin: 0 auto;">
            Start now and have the sealed document back today.
        </p>
        <div class="btns">
            @auth
                <a href="{{ route('client.request.create') }}" class="btn btn-primary btn-lg">Start a notarization</a>
            @else
                <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Create an account</a>
                <a href="{{ route('login') }}" class="btn btn-outline btn-lg">Sign in</a>
            @endauth
        </div>
    </div>
</section>

@endsection
