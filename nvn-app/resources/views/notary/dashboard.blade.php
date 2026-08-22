@extends('layouts.app', ['title' => 'Notary Dashboard'])

@push('styles')
<style>
    /* Override shell for this page — we manage our own layout */
    .shell { padding: 0 0 64px; max-width: 100%; }

    /* ── Hero header ── */
    .dash-hero {
        background: linear-gradient(135deg, #0f1a0b 0%, #1a3011 55%, #2a5020 100%);
        padding: 44px 0 0;
        position: relative;
        overflow: hidden;
    }
    .dash-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background: radial-gradient(ellipse 70% 80% at 10% 50%, rgba(84,180,53,.18) 0%, transparent 70%);
        pointer-events: none;
    }
    .dash-hero-inner {
        max-width: 1080px;
        margin: 0 auto;
        padding: 0 28px;
        position: relative;
    }
    .dash-hero-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 36px;
    }
    .dash-hero-top h1 {
        color: #fff;
        font-size: 26px;
        font-weight: 800;
        margin: 0 0 4px;
    }
    .dash-hero-top .sub {
        color: rgba(255,255,255,.6);
        font-size: 13px;
    }
    .dash-hero-top .btn-view {
        background: rgba(255,255,255,.12);
        color: #fff;
        border: 1px solid rgba(255,255,255,.25);
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
        transition: background .15s;
    }
    .dash-hero-top .btn-view:hover { background: rgba(255,255,255,.2); color: #fff; }

    /* Stats inside hero */
    .stats-band {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1px;
        background: rgba(255,255,255,.1);
        border-top: 1px solid rgba(255,255,255,.1);
        border-radius: 0;
    }
    @media (max-width: 600px) { .stats-band { grid-template-columns: 1fr; } }
    .stat-cell {
        padding: 24px 28px;
        background: rgba(0,0,0,.15);
        backdrop-filter: blur(4px);
        text-align: center;
    }
    .stat-cell:first-child { border-radius: 0; }
    .stat-cell-num {
        font-size: 40px;
        font-weight: 800;
        color: #78d44a;
        line-height: 1;
        margin-bottom: 6px;
    }
    .stat-cell-label {
        font-size: 12px;
        font-weight: 500;
        color: rgba(255,255,255,.6);
        text-transform: uppercase;
        letter-spacing: .06em;
    }

    /* ── Content area ── */
    .dash-body {
        background: var(--bg);
        min-height: 400px;
    }
    .dash-body-inner {
        max-width: 1080px;
        margin: 0 auto;
        padding: 32px 28px 48px;
    }

    /* Status banner */
    .status-banner {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 20px;
        border-radius: var(--radius);
        margin-bottom: 28px;
        font-size: 14px;
        font-weight: 500;
        box-shadow: var(--shadow-sm);
    }
    .status-banner.warning { background: var(--warning-bg); border: 1px solid #e8c97a; color: var(--warning); }
    .status-banner.danger  { background: var(--danger-bg);  border: 1px solid var(--danger-line); color: var(--danger); }
    .status-banner.success { background: var(--success-bg); border: 1px solid var(--success-line); color: var(--success); }
    .status-banner a { font-weight: 700; color: inherit; text-decoration: underline; }

    /* Section heading */
    .section-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
    }
    .section-head h2 { margin: 0; font-size: 15px; font-weight: 700; color: var(--ink); text-transform: uppercase; letter-spacing: .05em; }
    .badge-count {
        background: #fff3cd;
        color: #8a5a00;
        border: 1px solid #e8c97a;
        font-size: 11px;
        font-weight: 700;
        padding: 2px 10px;
        border-radius: 999px;
    }

    /* Request card */
    .req-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 18px 20px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        box-shadow: var(--shadow-sm);
        transition: box-shadow .15s, border-color .15s;
    }
    .req-card:hover { box-shadow: var(--shadow); border-color: #c8d8c0; }
    .req-ref { font-weight: 700; font-size: 15px; color: var(--ink); margin-bottom: 4px; }
    .req-meta { font-size: 12px; color: var(--muted); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .req-meta-dot { width: 3px; height: 3px; background: var(--muted); border-radius: 50%; }
    .req-actions { display: flex; gap: 8px; flex-shrink: 0; }
    .req-actions .btn { padding: 8px 18px; font-size: 13px; }

    /* Quick links */
    .quick-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 14px;
        margin-top: 36px;
    }
    .quick-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 22px 20px;
        text-decoration: none;
        display: flex;
        align-items: flex-start;
        gap: 16px;
        box-shadow: var(--shadow-sm);
        transition: box-shadow .15s, border-color .15s, transform .15s;
    }
    .quick-card:hover {
        box-shadow: var(--shadow);
        border-color: var(--brand);
        transform: translateY(-2px);
    }
    .quick-icon {
        width: 44px;
        height: 44px;
        background: var(--brand-light);
        border-radius: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--brand);
        flex-shrink: 0;
    }
    .quick-title { font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 3px; }
    .quick-sub   { font-size: 12px; color: var(--muted); line-height: 1.5; }

    /* Empty state */
    .empty-state {
        background: var(--surface);
        border: 2px dashed var(--line);
        border-radius: var(--radius-lg);
        padding: 64px 24px;
        text-align: center;
    }
    .empty-icon { color: var(--brand); opacity: .4; margin-bottom: 16px; }
    .empty-title { font-size: 16px; font-weight: 700; color: var(--ink); margin-bottom: 6px; }
    .empty-sub { font-size: 13px; color: var(--muted); line-height: 1.65; }

    /* ── Analytics ──
       Charts are hand-rolled CSS/SVG rather than a charting library. The app
       has no JS build step and ships to shared hosting, so this keeps the
       dashboard dependency-free and lets the bars use the same design tokens
       as everything else. */
    .an-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 14px;
        margin-bottom: 32px;
    }
    .an-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        padding: 20px 22px;
        box-shadow: var(--shadow-sm);
    }
    .an-card.wide { grid-column: 1 / -1; }
    .an-card h3 {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: var(--muted);
        font-weight: 700;
        margin: 0 0 3px;
    }
    .an-card .an-sub { font-size: 12px; color: var(--muted); margin: 0 0 16px; line-height: 1.5; }
    .an-card .an-sub strong { color: var(--brand-dark); }

    /* Trailing-30-day column chart */
    .an-bars { display: flex; align-items: flex-end; gap: 2px; height: 110px; }
    .an-bar {
        flex: 1;
        background: var(--brand-light);
        border-radius: 2px 2px 0 0;
        min-height: 2px;
        position: relative;
        transition: background .15s;
    }
    .an-bar[data-v]:not([data-v="0"]) { background: var(--brand); }
    .an-bar:hover { background: var(--brand-dark); }
    .an-bar-axis {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        color: var(--muted);
        margin-top: 7px;
    }

    /* Weekday chart — labelled rows, peak highlighted */
    .an-rows { display: flex; flex-direction: column; gap: 8px; }
    .an-row { display: grid; grid-template-columns: 42px 1fr 30px; align-items: center; gap: 10px; }
    .an-row-label { font-size: 12px; color: var(--muted); font-weight: 500; }
    .an-row-track { background: var(--bg); border-radius: 999px; height: 9px; overflow: hidden; }
    /* --brand-light is a background tint and disappears against --bg, so the
       bars use a translucent brand green and the peak uses it at full strength. */
    .an-row-fill { background: rgba(84, 180, 53, .42); height: 100%; border-radius: 999px; min-width: 2px; }
    .an-row.peak .an-row-fill { background: var(--brand); }
    .an-row.peak .an-row-label { color: var(--brand-dark); font-weight: 700; }
    .an-row-val { font-size: 12px; color: var(--ink); font-weight: 600; text-align: right; }

    /* Status doughnut */
    .an-donut-wrap { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
    .an-donut { flex-shrink: 0; transform: rotate(-90deg); }
    .an-legend { flex: 1; min-width: 150px; display: flex; flex-direction: column; gap: 7px; }
    .an-legend-item { display: flex; align-items: center; gap: 8px; font-size: 12.5px; }
    .an-swatch { width: 9px; height: 9px; border-radius: 2px; flex-shrink: 0; }
    .an-legend-label { flex: 1; color: var(--muted); }
    .an-legend-val { font-weight: 700; color: var(--ink); }

    /* Earnings */
    .an-money { font-size: 27px; font-weight: 800; color: var(--ink); line-height: 1.1; margin-bottom: 3px; }
    .an-money-sub { font-size: 12px; color: var(--muted); }
    .an-split { display: flex; gap: 22px; flex-wrap: wrap; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--line); }
    .an-split div { min-width: 0; }
    .an-split .k { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); font-weight: 600; }
    .an-split .v { font-size: 15px; font-weight: 700; color: var(--ink); margin-top: 2px; }

    .an-payout { display: flex; gap: 22px; flex-wrap: wrap; margin-top: 14px; padding-top: 14px; border-top: 1px dashed var(--line); }
    .an-payout .k { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); font-weight: 600; }
    .an-payout .v { font-size: 15px; font-weight: 700; color: var(--ink); margin-top: 2px; }
    .an-payout .v.is-due { color: #b8860b; }
    .an-note { font-size: 11.5px; color: var(--muted); line-height: 1.55; margin-top: 10px; }

    .an-empty { font-size: 12.5px; color: var(--muted); text-align: center; padding: 26px 0; line-height: 1.6; }
</style>
@endpush

@section('content')

{{-- ── Hero ── --}}
<div class="dash-hero">
    <div class="dash-hero-inner">
        <div class="dash-hero-top">
            <div>
                <h1>Welcome back, {{ explode(' ', $user->full_name)[0] }}</h1>
                <div class="sub">
                    {{ ($isAdminDesk ?? false) ? 'Platform Notary Desk' : 'Notary Partner Dashboard' }}
                    &nbsp;·&nbsp; {{ now()->format('l, j F Y') }}
                </div>
            </div>
            <a href="{{ route('notary.requests.incoming') }}" class="btn-view">View all requests &rarr;</a>
        </div>
    </div>

    <div class="stats-band">
        <div class="stat-cell">
            <div class="stat-cell-num">{{ $pendingRequests->count() }}</div>
            <div class="stat-cell-label">Awaiting response</div>
        </div>
        <div class="stat-cell">
            <div class="stat-cell-num">{{ $activeSessions->count() }}</div>
            <div class="stat-cell-label">Active sessions</div>
        </div>
        <div class="stat-cell">
            <div class="stat-cell-num">{{ $completedCount }}</div>
            <div class="stat-cell-label">Completed total</div>
        </div>
    </div>
</div>

{{-- ── Body ── --}}
<div class="dash-body">
    <div class="dash-body-inner">

        {{-- Profile status banner --}}
        {{-- The admin runs the platform's own notary profile. None of the
             onboarding / go-live states apply to them, and every link in that
             branch points at a role:notary route they would 403 on. --}}
        @if($isAdminDesk ?? false)
        <div class="status-banner success">
            <x-heroicon-o-shield-check style="width:20px;height:20px;flex-shrink:0;"/>
            You are working as the platform's own notary. This desk shows requests booked with
            {{ $profile?->organization_name ?? $user->full_name }}, plus any job a partner declined or let time out.
            <a href="{{ route('filament.admin.pages.dashboard') }}">Back to the admin panel &rarr;</a>
        </div>
        @elseif(! $profile)
        <div class="status-banner danger">
            <x-heroicon-o-exclamation-circle style="width:20px;height:20px;flex-shrink:0;"/>
            Your notary profile hasn't been set up yet. Please complete the onboarding steps.
        </div>
        @elseif(! $profile->onboarding_fee_paid_at)
        <div class="status-banner danger">
            <x-heroicon-o-credit-card style="width:20px;height:20px;flex-shrink:0;"/>
            Your membership fee is not yet paid. <a href="{{ route('notary.onboarding.fee') }}">Pay now &rarr;</a>
        </div>
        {{-- A lapse comes before the review and listing states: it is what is
             actually keeping them out of the marketplace, and an approved,
             listed notary who has lapsed would otherwise be told they are live. --}}
        @elseif($profile->membershipLapsed())
        <div class="status-banner danger">
            <x-heroicon-o-exclamation-circle style="width:20px;height:20px;flex-shrink:0;"/>
            Your membership ended on {{ $profile->membership_expires_at->format('j F Y') }},
            so clients can no longer find or book you.
            <a href="{{ route('notary.onboarding.fee') }}">Renew for a year &rarr;</a>
        </div>
        @elseif($profile->membershipEndingSoon())
        <div class="status-banner warning">
            <x-heroicon-o-clock style="width:20px;height:20px;flex-shrink:0;"/>
            Your membership ends on {{ $profile->membership_expires_at->format('j F Y') }} —
            {{ $profile->membershipDaysLeft() }} days left.
            <a href="{{ route('notary.onboarding.fee') }}">Renew now &rarr;</a>
        </div>
        @elseif($profile->verification_status === 'pending')
        <div class="status-banner warning">
            <x-heroicon-o-clock style="width:20px;height:20px;flex-shrink:0;"/>
            Your application is under review. We'll email you once a decision is made — usually within 48 hours.
        </div>
        @elseif($profile->verification_status === 'rejected')
        <div class="status-banner danger">
            <x-heroicon-o-x-circle style="width:20px;height:20px;flex-shrink:0;"/>
            Your application was not approved. Please contact support for more information.
        </div>
        @elseif($profile->isAwaitingListingReview())
        <div class="status-banner warning">
            <x-heroicon-o-clock style="width:20px;height:20px;flex-shrink:0;"/>
            Your profile is with us for review. We look at every notary's signature, stamp and seal
            by hand before listing them, so this takes a little longer than the rest — usually a day.
        </div>
        @elseif(! $profile->public_listing_enabled)
        <div class="status-banner warning">
            <x-heroicon-o-eye-slash style="width:20px;height:20px;flex-shrink:0;"/>
            @if($profile->listing_review_notes)
                {{-- The reason, on the page they land on, not only in an email
                     they may have deleted. A notary who cannot remember what was
                     wrong resubmits the same file. --}}
                We could not list you yet: {{ $profile->listing_review_notes }}
                <a href="{{ route('notary.profile.edit') }}">Fix it and send it back &rarr;</a>
            @else
                Your profile is approved but not yet listed publicly. <a href="{{ route('notary.profile.edit') }}">Complete your profile and ask to be listed &rarr;</a>
            @endif
        </div>
        @else
        <div class="status-banner success">
            <x-heroicon-o-check-circle style="width:20px;height:20px;flex-shrink:0;"/>
            Your profile is live — clients can find and book you in the marketplace.
        </div>
        @endif

        {{-- Pending requests --}}
        @if($pendingRequests->isNotEmpty())
        <div style="margin-bottom:32px;">
            <div class="section-head">
                <h2>{{ ($isAdminDesk ?? false) ? 'Awaiting a notary' : 'Awaiting your response' }}</h2>
                <span class="badge-count">{{ $pendingRequests->count() }} pending</span>
            </div>
            @foreach($pendingRequests as $req)
            <div class="req-card">
                <div>
                    <div class="req-ref">{{ $req->reference }}</div>
                    <div class="req-meta">
                        <span>{{ $req->client->full_name }}</span>
                        @if($req->service?->service_type)
                            <span class="req-meta-dot"></span>
                            <span>{{ $req->service->service_type }}</span>
                        @endif
                        @if(($isAdminDesk ?? false) && $req->notary)
                            <span class="req-meta-dot"></span>
                            <span>booked with {{ $req->notary->user->full_name }}</span>
                        @endif
                        @if($req->session?->scheduled_start_at)
                            <span class="req-meta-dot"></span>
                            <span style="display:flex;align-items:center;gap:4px;">
                                <x-heroicon-o-calendar style="width:12px;height:12px;"/>
                                {{ $req->session->scheduled_start_at->format('j M Y · g:i A') }}
                            </span>
                        @endif
                    </div>
                    {{-- Informational only. The window passing does not move the
                         request anywhere; it just says how long the client has
                         been waiting, and that nothing is sealed yet. --}}
                    <div class="req-meta" style="margin-top:4px; color:{{ $req->isOverdue() ? 'var(--danger)' : 'var(--muted)' }};">
                        <span style="display:flex;align-items:center;gap:4px;">
                            <x-heroicon-o-clock style="width:12px;height:12px;"/>
                            @if($req->submitted_at)
                                uploaded {{ $req->submitted_at->diffForHumans() }},
                            @endif
                            paid {{ $req->paid_at?->diffForHumans() ?? '—' }}
                        </span>
                        <span class="req-meta-dot"></span>
                        <span>{{ $req->isOverdue() ? 'past the response window' : 'not yet notarized' }}</span>
                    </div>
                </div>
                <div class="req-actions">
                    <a href="{{ route('notary.requests.show', $req) }}" class="btn btn-ghost">View</a>
                    <form method="POST" action="{{ route('notary.requests.accept', $req) }}" style="margin:0;">
                        @csrf
                        {{-- For the admin this is a take-over: the controller
                             keeps the booking, and the seal, with the notary the
                             client selected. --}}
                        <button type="submit" class="btn">
                            {{ ($isAdminDesk ?? false) && ! ($req->notary?->is_system_native ?? false) ? 'Take over' : 'Accept' }}
                        </button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
        @endif

        {{-- Active sessions --}}
        @if($activeSessions->isNotEmpty())
        <div style="margin-bottom:32px;">
            <div class="section-head">
                <h2>Active sessions</h2>
            </div>
            @foreach($activeSessions as $req)
            <div class="req-card">
                <div>
                    <div class="req-ref">{{ $req->reference }}</div>
                    <div class="req-meta">
                        <span>{{ $req->client->full_name }}</span>
                        <span class="req-meta-dot"></span>
                        <span class="pill">{{ $req->status->label() }}</span>
                        @if($req->session?->scheduled_start_at)
                            <span class="req-meta-dot"></span>
                            <span style="display:flex;align-items:center;gap:4px;">
                                <x-heroicon-o-calendar style="width:12px;height:12px;"/>
                                {{ $req->session->scheduled_start_at->format('j M Y · g:i A') }}
                            </span>
                        @endif
                    </div>
                </div>
                <div class="req-actions">
                    <a href="{{ route('notary.requests.show', $req) }}" class="btn btn-ghost">View</a>
                    <a href="{{ route('session.join', $req) }}" class="btn">Join session &rarr;</a>
                </div>
            </div>
            @endforeach
        </div>
        @endif

        {{-- ── Your numbers ───────────────────────────────────────────── --}}
        <div class="section-head">
            <h2>Your numbers</h2>
        </div>

        @if($stats['received'] === 0)
            <div class="an-card wide" style="margin-bottom:32px;">
                <div class="an-empty">
                    Your charts appear here once you have taken your first request.<br>
                    Nothing to plot yet.
                </div>
            </div>
        @else
        <div class="an-grid">

            {{-- Earnings --}}
            <div class="an-card">
                <h3>{{ $isAdminDesk ? 'Platform earnings' : 'Your earnings' }}</h3>
                <p class="an-sub">
                    @if ($isAdminDesk)
                        Your own jobs after commission, plus commission on jobs you covered.
                        A partner always keeps their full share of a job you notarized for them.
                    @else
                        After the platform's {{ $earnings['rate'] }}% commission, on completed jobs.
                    @endif
                </p>
                <div class="an-money">{{ \App\Support\Analytics::money($earnings['share']) }}</div>
                <div class="an-money-sub">all time</div>
                <div class="an-split">
                    <div>
                        <div class="k">This month</div>
                        <div class="v">{{ \App\Support\Analytics::money($earnings['thisMonth']) }}</div>
                    </div>
                    @if ($isAdminDesk)
                    <div>
                        <div class="k">From covered jobs</div>
                        <div class="v">{{ \App\Support\Analytics::money($earnings['covered']) }}</div>
                    </div>
                    @else
                    <div>
                        <div class="k">Gross collected</div>
                        <div class="v">{{ \App\Support\Analytics::money($earnings['gross']) }}</div>
                    </div>
                    @endif
                    <div>
                        <div class="k">Acceptance rate</div>
                        <div class="v">{{ $stats['acceptRate'] }}%</div>
                    </div>
                </div>

                {{-- Earned and actually received are different things: a job counts
                     the moment it completes, but the money moves on a payout run. --}}
                @unless ($isAdminDesk)
                <div class="an-payout">
                    <div>
                        <div class="k">Paid out to you</div>
                        <div class="v">{{ \App\Support\Analytics::money($earnings['paidOut']) }}</div>
                    </div>
                    <div>
                        <div class="k">Awaiting payout</div>
                        <div class="v {{ $earnings['owed'] > 0 ? 'is-due' : '' }}">
                            {{ \App\Support\Analytics::money($earnings['owed']) }}
                        </div>
                    </div>
                </div>
                <p class="an-note">
                    @if ($earnings['owed'] > 0)
                        Payouts go to the account on your profile.
                        @if (\App\Support\Settings::paystackTransfersEnabled())
                            Make sure your bank details are verified so yours can be sent automatically.
                        @else
                            They are sent by bank transfer, so keep your account details up to date.
                        @endif
                    @else
                        Everything earned on completed jobs has been paid out or is on its way.
                    @endif
                </p>
                @endunless
            </div>

            {{-- Status mix --}}
            @php
                $mixTotal  = array_sum($statusMix);
                $mixColors = ['#54B435', '#7cc4f0', '#f5a524', '#9b8afb', '#4f9fd8', '#e0684f', '#cbd5e1'];
                // Doughnut geometry: circumference of an r=52 circle, drawn with
                // stroke-dasharray so no charting library is needed.
                $circ = 2 * M_PI * 52;
                $offset = 0;
            @endphp
            <div class="an-card">
                <h3>Where your requests sit</h3>
                <p class="an-sub"><strong>{{ $stats['received'] }}</strong> received in total.</p>
                <div class="an-donut-wrap">
                    <svg class="an-donut" width="128" height="128" viewBox="0 0 128 128" role="img"
                         aria-label="Breakdown of your requests by status">
                        @foreach($statusMix as $label => $count)
                            @php
                                $frac  = $mixTotal > 0 ? $count / $mixTotal : 0;
                                $len   = $circ * $frac;
                                $color = $mixColors[$loop->index % count($mixColors)];
                            @endphp
                            <circle cx="64" cy="64" r="52" fill="none" stroke="{{ $color }}" stroke-width="18"
                                    stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}"
                                    stroke-dashoffset="{{ round(-$offset, 2) }}"></circle>
                            @php $offset += $len; @endphp
                        @endforeach
                    </svg>
                    <div class="an-legend">
                        @foreach($statusMix as $label => $count)
                            <div class="an-legend-item">
                                <span class="an-swatch" style="background:{{ $mixColors[$loop->index % count($mixColors)] }};"></span>
                                <span class="an-legend-label">{{ $label }}</span>
                                <span class="an-legend-val">{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Busiest weekday --}}
            @php $wdMax = max($weekday['values'] ?: [0]); @endphp
            <div class="an-card">
                <h3>Busiest day of the week</h3>
                <p class="an-sub">
                    @if($weekday['busiest'])
                        Most of your work arrives on a <strong>{{ $weekday['busiest'] }}</strong>.
                    @else
                        Not enough data yet.
                    @endif
                </p>
                <div class="an-rows">
                    @foreach($weekday['labels'] as $i => $label)
                        @php $v = $weekday['values'][$i]; @endphp
                        <div class="an-row {{ $v > 0 && $v === $wdMax ? 'peak' : '' }}">
                            <span class="an-row-label">{{ $label }}</span>
                            <span class="an-row-track">
                                <span class="an-row-fill" style="width:{{ $wdMax > 0 ? round($v / $wdMax * 100) : 0 }}%;"></span>
                            </span>
                            <span class="an-row-val">{{ $v }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Time of day --}}
            @php $hrMax = max($hours['values'] ?: [0]); @endphp
            <div class="an-card">
                <h3>When requests come in</h3>
                <p class="an-sub">Use this to set the availability hours that actually get booked.</p>
                <div class="an-rows">
                    @foreach($hours['labels'] as $i => $label)
                        @php $v = $hours['values'][$i]; @endphp
                        <div class="an-row {{ $v > 0 && $v === $hrMax ? 'peak' : '' }}" style="grid-template-columns:100px 1fr 30px;">
                            <span class="an-row-label">{{ $label }}</span>
                            <span class="an-row-track">
                                <span class="an-row-fill" style="width:{{ $hrMax > 0 ? round($v / $hrMax * 100) : 0 }}%;"></span>
                            </span>
                            <span class="an-row-val">{{ $v }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- 30-day trend --}}
            @php $dMax = max($daily['values'] ?: [0]); @endphp
            <div class="an-card wide">
                <h3>Last 30 days</h3>
                <p class="an-sub">
                    <strong>{{ array_sum($daily['values']) }}</strong> requests in the period ·
                    peak day <strong>{{ $dMax }}</strong>.
                </p>
                <div class="an-bars">
                    @foreach($daily['values'] as $i => $v)
                        <span class="an-bar" data-v="{{ $v }}"
                              style="height:{{ $dMax > 0 ? max(2, round($v / $dMax * 100)) : 2 }}%;"
                              title="{{ $daily['labels'][$i] }} — {{ $v }} request{{ $v === 1 ? '' : 's' }}"></span>
                    @endforeach
                </div>
                <div class="an-bar-axis">
                    <span>{{ $daily['labels'][0] }}</span>
                    <span>{{ $daily['labels'][count($daily['labels']) - 1] }}</span>
                </div>
            </div>

        </div>
        @endif

        {{-- Empty state --}}
        @if($pendingRequests->isEmpty() && $activeSessions->isEmpty())
        <div class="empty-state" style="margin-bottom:32px;">
            <div class="empty-icon"><x-heroicon-o-clipboard style="width:52px;height:52px;"/></div>
            <div class="empty-title">No active requests right now</div>
            @if($isAdminDesk ?? false)
            <div class="empty-sub">Nothing on the platform desk right now.<br>Fallback jobs and system-notary bookings land here automatically.</div>
            <a href="{{ route('filament.admin.pages.dashboard') }}" class="btn btn-ghost" style="margin-top:20px;display:inline-block;">Back to admin panel</a>
            @else
            <div class="empty-sub">New requests will appear here as clients book you.<br>Make sure your profile is live and up-to-date.</div>
            <a href="{{ route('notary.profile.edit') }}" class="btn btn-ghost" style="margin-top:20px;display:inline-block;">Edit profile</a>
            @endif
        </div>
        @endif

        {{-- Quick links --}}
        <div class="quick-grid">
            <a href="{{ route('notary.requests.incoming') }}" class="quick-card">
                <div class="quick-icon"><x-heroicon-o-inbox style="width:22px;height:22px;"/></div>
                <div>
                    <div class="quick-title">All Requests</div>
                    <div class="quick-sub">View all paid requests awaiting your action</div>
                </div>
            </a>
            {{-- Work the notary took on themselves, brought here only to be
                 sealed. Deliberately alongside the marketplace links and not
                 buried in the profile: it is a second way of earning, and a
                 notary standing in front of a walk-in client needs to reach it
                 in one tap. --}}
            <a href="{{ route('notary.offsite.index') }}" class="quick-card">
                <div class="quick-icon"><x-heroicon-o-briefcase style="width:22px;height:22px;"/></div>
                <div>
                    <div class="quick-title">Offsite Notarization</div>
                    <div class="quick-sub">Seal a document from a job you took on yourself — {{ \App\Support\Settings::offsiteFeeDisplay() }} per document</div>
                </div>
            </a>
            @if($isAdminDesk ?? false)
            {{-- notary.profile.edit is role:notary — the admin manages the
                 system-native profile and its assets from the panel instead. --}}
            <a href="{{ route('filament.admin.resources.notary-profiles.index') }}" class="quick-card">
                <div class="quick-icon"><x-heroicon-o-identification style="width:22px;height:22px;"/></div>
                <div>
                    <div class="quick-title">Notary Profiles</div>
                    <div class="quick-sub">Manage the platform notary's signature, stamp and seal</div>
                </div>
            </a>
            @else
            <a href="{{ route('notary.profile.edit') }}" class="quick-card">
                <div class="quick-icon"><x-heroicon-o-user style="width:22px;height:22px;"/></div>
                <div>
                    <div class="quick-title">My Profile</div>
                    <div class="quick-sub">Update signature, seal, pricing, and bank details</div>
                </div>
            </a>
            @endif
        </div>

    </div>
</div>

@endsection
