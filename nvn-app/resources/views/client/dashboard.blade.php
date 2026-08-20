@extends('layouts.app', ['title' => 'My Dashboard — Naija Virtual Notary'])

@push('styles')
<style>
    .shell { padding: 0 0 64px; max-width: 100%; }

    /* ── Hero ── */
    .dash-hero {
        background: linear-gradient(135deg, #0f1a0b 0%, #1a3011 55%, #2a5020 100%);
        padding: 44px 0 0;
        position: relative;
        overflow: hidden;
    }
    .dash-hero::before {
        content: '';
        position: absolute; inset: 0;
        background: radial-gradient(ellipse 70% 80% at 90% 50%, rgba(84,180,53,.15) 0%, transparent 70%);
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
    .dash-hero-top h1 { color: #fff; font-size: 26px; font-weight: 800; margin-bottom: 4px; }
    .dash-hero-top .sub { color: rgba(255,255,255,.55); font-size: 13px; }
    .btn-hero {
        background: rgba(255,255,255,.12);
        color: #fff;
        border: 1px solid rgba(255,255,255,.25);
        padding: 11px 22px;
        border-radius: 9px;
        font-size: 13px;
        font-weight: 600;
        font-family: var(--font);
        text-decoration: none;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        transition: background .15s;
    }
    .btn-hero:hover { background: rgba(255,255,255,.2); color: #fff; }

    /* Stats band */
    .stats-band {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        border-top: 1px solid rgba(255,255,255,.1);
        gap: 1px;
        background: rgba(255,255,255,.08);
    }
    @media (max-width: 600px) { .stats-band { grid-template-columns: 1fr; } }
    .stat-cell { padding: 22px 28px; background: rgba(0,0,0,.18); text-align: center; }
    .stat-cell-num   { font-size: 38px; font-weight: 800; color: #78d44a; line-height: 1; margin-bottom: 5px; }
    .stat-cell-label { font-size: 11px; font-weight: 600; color: rgba(255,255,255,.55); text-transform: uppercase; letter-spacing: .07em; }

    /* ── Body ── */
    .dash-body { background: var(--bg); }
    .dash-body-inner { max-width: 1080px; margin: 0 auto; padding: 32px 28px 60px; }

    /* Section heading */
    .section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
    .section-head h2 { margin: 0; font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .09em; }
    .section-head a { font-size: 13px; color: var(--brand); font-weight: 500; }

    /* Request row */
    .req-row {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 16px 20px;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
        box-shadow: var(--shadow-sm);
        transition: box-shadow .15s, border-color .15s;
        text-decoration: none;
        color: inherit;
    }
    .req-row:hover { box-shadow: var(--shadow); border-color: #c8d8c0; color: inherit; }
    .req-ref { font-weight: 700; font-size: 14px; color: var(--ink); margin-bottom: 3px; }
    .req-meta { font-size: 12px; color: var(--muted); display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
    .req-dot { width: 3px; height: 3px; background: currentColor; border-radius: 50%; opacity: .4; }

    /* Action cluster on the right of a request row. Wraps under the reference
       on narrow screens rather than squashing the buttons. */
    .req-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; flex-shrink: 0; }
    .req-wait {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        color: var(--muted);
        padding: 5px 2px;
    }
    @media (max-width: 560px) {
        .req-actions { width: 100%; }
        .req-actions .btn { flex: 1; justify-content: center; }
    }

    /* Empty state */
    .empty-card {
        background: var(--surface);
        border: 2px dashed var(--line);
        border-radius: var(--radius-lg);
        padding: 48px 24px;
        text-align: center;
        color: var(--muted);
        margin-bottom: 8px;
    }
    .empty-icon { color: var(--brand); opacity: .35; margin-bottom: 14px; }
    .empty-card p  { font-weight: 600; color: var(--ink); margin-bottom: 4px; font-size: 15px; }
    .empty-card small { font-size: 13px; }

    /* Quick links */
    .quick-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-top: 36px; }
    .quick-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 20px;
        text-decoration: none;
        display: flex;
        align-items: flex-start;
        gap: 14px;
        box-shadow: var(--shadow-sm);
        transition: box-shadow .15s, border-color .15s, transform .15s;
        color: inherit;
    }
    .quick-card:hover { box-shadow: var(--shadow); border-color: var(--brand); transform: translateY(-2px); color: inherit; }
    .quick-icon { width: 42px; height: 42px; background: var(--brand-light); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--brand); flex-shrink: 0; }
    .quick-title { font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 3px; }
    .quick-sub   { font-size: 12px; color: var(--muted); line-height: 1.5; }

    /* Download badge */
    .download-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: var(--success-bg);
        color: var(--success);
        border: 1px solid var(--success-line);
        font-size: 12px;
        font-weight: 600;
        padding: 3px 10px;
        border-radius: 999px;
        text-decoration: none;
    }
    .download-badge:hover { background: var(--brand-light); color: var(--brand-dark); border-color: var(--brand); }
</style>
@endpush

@section('content')

{{-- Hero --}}
<div class="dash-hero">
    <div class="dash-hero-inner">
        <div class="dash-hero-top">
            <div>
                <h1>Welcome back, {{ explode(' ', auth()->user()->full_name)[0] }}</h1>
                <div class="sub">Client Dashboard &nbsp;·&nbsp; {{ now()->format('l, j F Y') }}</div>
            </div>
            <a href="{{ route('client.request.create') }}" class="btn-hero">
                <x-heroicon-o-plus style="width:15px;height:15px;"/>
                New notarization
            </a>
        </div>
    </div>

    <div class="stats-band">
        <div class="stat-cell">
            <div class="stat-cell-num">{{ $drafts->count() }}</div>
            <div class="stat-cell-label">In progress</div>
        </div>
        <div class="stat-cell">
            <div class="stat-cell-num">{{ $active->count() }}</div>
            <div class="stat-cell-label">Active</div>
        </div>
        <div class="stat-cell">
            <div class="stat-cell-num">{{ $completed->count() }}</div>
            <div class="stat-cell-label">Completed</div>
        </div>
    </div>
</div>

{{-- Body --}}
<div class="dash-body">
    <div class="dash-body-inner">

        {{-- In progress / drafts --}}
        @if($drafts->count())
        <div style="margin-bottom:32px;">
            <div class="section-head">
                <h2>In progress</h2>
            </div>
            @foreach($drafts as $req)
            <a href="{{ route('client.marketplace.index', $req) }}" class="req-row">
                <div>
                    <div class="req-ref">{{ $req->reference }}</div>
                    <div class="req-meta">
                        <span class="pill pill-pending">Draft</span>
                        <span class="req-dot"></span>
                        <span>{{ $req->created_at->format('j M Y') }}</span>
                    </div>
                </div>
                <span class="btn btn-sm btn-ghost">Continue &rarr;</span>
            </a>
            @endforeach
        </div>
        @endif

        {{-- Active requests --}}
        <div style="margin-bottom:32px;">
            <div class="section-head">
                <h2>Active requests</h2>
            </div>
            @forelse($active as $req)
            <div class="req-row">
                <div>
                    <div class="req-ref">{{ $req->reference }}</div>
                    <div class="req-meta">
                        <span class="pill">{{ $req->status->label() }}</span>
                        @if($req->notary)
                            <span class="req-dot"></span>
                            <span>{{ $req->notary->user->full_name }}</span>
                        @endif
                        @if($req->session?->scheduled_start_at)
                            <span class="req-dot"></span>
                            <span style="display:flex;align-items:center;gap:4px;">
                                <x-heroicon-o-calendar style="width:12px;height:12px;"/>
                                {{ $req->session->scheduled_start_at->format('j M Y · g:i A') }}
                            </span>
                        @elseif($req->notary)
                            <span class="req-dot"></span>
                            <span>No fixed time</span>
                        @endif
                    </div>
                    {{-- Said here as well as behind the button, because "your
                         payment is safe" is the part that stops a client
                         phoning, and a button label has no room for it. --}}
                    @if($req->isCategoryBlocked())
                        <div class="req-meta" style="color:var(--warning); margin-top:4px;">
                            <x-heroicon-o-exclamation-triangle style="width:13px;height:13px;"/>
                            <span>
                                {{ $req->hasOpenCategoryQuery()
                                    ? 'Booked under the wrong category — your payment is safe.'
                                    : $req->displayBalance() . ' left to pay after the category was corrected.' }}
                            </span>
                        </div>
                    @endif
                </div>
                <div class="req-actions">
                    {{-- The thread only exists once a notary is assigned — before that
                         there is nobody on the other end to read it. --}}
                    @if($req->notary)
                        <a href="{{ route('messages.show', $req) }}" class="btn btn-sm btn-ghost">
                            <x-heroicon-o-chat-bubble-left-right style="width:14px;height:14px;"/>
                            Messages
                        </a>
                    @endif

                    {{-- A queried category outranks the status. The request may
                         say "Paid" and be perfectly on track by every other
                         measure, and still be going nowhere until this is
                         answered — so it takes the one action slot. --}}
                    @if($req->isCategoryBlocked())
                        <a href="{{ route('client.request.category.show', $req) }}" class="btn btn-sm">
                            {{ $req->hasOpenCategoryQuery()
                                ? 'Choose the right category →'
                                : 'Pay ' . $req->displayBalance() . ' to continue →' }}
                        </a>
                    @else

                    {{-- The action has to match the status. "Join session" on a
                         request that is still awaiting payment or a notary's
                         acceptance sends the client into a call nobody will join. --}}
                    @switch($req->status)
                        @case(\App\Enums\RequestStatus::Submitted)
                            <a href="{{ route('client.request.payment.status', $req) }}" class="btn btn-sm">Complete payment &rarr;</a>
                            @break
                        @case(\App\Enums\RequestStatus::Paid)
                            <span class="req-wait">
                                <x-heroicon-o-clock style="width:13px;height:13px;"/>
                                Waiting for your notary to accept
                            </span>
                            @break
                        @default
                            <a href="{{ route('session.join', $req) }}" class="btn btn-sm">Join session &rarr;</a>
                    @endswitch
                    @endif
                </div>
            </div>
            @empty
            <div class="empty-card">
                <div class="empty-icon"><x-heroicon-o-document-text style="width:44px;height:44px;"/></div>
                <p>No active requests</p>
                <small>Submit a new request above and a licensed notary will be assigned.</small>
            </div>
            @endforelse
        </div>

        {{-- Completed --}}
        <div style="margin-bottom:0;">
            <div class="section-head">
                <h2>Completed</h2>
            </div>
            @forelse($completed as $req)
            <div class="req-row">
                <div>
                    <div class="req-ref">{{ $req->reference }}</div>
                    <div class="req-meta">
                        <span class="pill pill-approved">Notarized</span>
                        @if($req->notary)
                            <span class="req-dot"></span>
                            <span>{{ $req->notary->user->full_name }}</span>
                        @endif
                        <span class="req-dot"></span>
                        {{-- completed_at is the real completion date; updated_at
                             shifts every time anything touches the row. --}}
                        <span>{{ ($req->completed_at ?? $req->updated_at)->format('j M Y') }}</span>
                    </div>
                </div>
                {{-- One badge per sealed document. A request with several is one
                     job but several finished files, and merging them would hand
                     someone a single PDF to take to three different places. --}}
                @if($req->finalDocuments->isNotEmpty())
                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                    @foreach($req->finalDocuments as $i => $final)
                    <a href="{{ route('client.documents.download', [$req, 'document' => $final->id]) }}"
                       class="download-badge"
                       title="{{ $final->sourceDocument?->label() ?? $final->original_filename }}">
                        <x-heroicon-o-arrow-down-tray style="width:12px;height:12px;"/>
                        {{ $req->finalDocuments->count() > 1 ? 'Document ' . ($i + 1) : 'Download' }}
                    </a>
                    @endforeach
                </div>
                @endif
            </div>
            @empty
            <div class="empty-card">
                <div class="empty-icon"><x-heroicon-o-check-badge style="width:44px;height:44px;"/></div>
                <p>No completed notarizations yet</p>
                <small>Your notarized documents will appear here once ready.</small>
            </div>
            @endforelse
        </div>

        {{-- Quick links --}}
        <div class="quick-grid">
            <a href="{{ route('client.request.create') }}" class="quick-card">
                <div class="quick-icon"><x-heroicon-o-document-plus style="width:20px;height:20px;"/></div>
                <div>
                    <div class="quick-title">New Request</div>
                    <div class="quick-sub">Upload a document and start the notarization process</div>
                </div>
            </a>
            <a href="{{ route('how-it-works') }}" class="quick-card">
                <div class="quick-icon"><x-heroicon-o-information-circle style="width:20px;height:20px;"/></div>
                <div>
                    <div class="quick-title">How It Works</div>
                    <div class="quick-sub">Learn about the four-step notarization process</div>
                </div>
            </a>
        </div>

    </div>
</div>

@endsection
