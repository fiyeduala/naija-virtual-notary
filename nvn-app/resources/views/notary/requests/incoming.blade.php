@extends('layouts.app', ['title' => 'Incoming requests'])

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('notary.dashboard') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to dashboard
        </a>
        <h1>Incoming requests</h1>
        <div class="sub">
            @if ($isAdminDesk ?? false)
                Every paid request awaiting a response, longest wait first. You can take any of them over
                on the assigned notary's behalf — it is sealed with their signature, stamp and seal.
            @else
                Paid requests awaiting your acceptance — please respond within {{ \App\Support\Settings::fallbackMinutes() }} minutes
            @endif
        </div>
    </div>
</div>

<div class="shell">
    @forelse ($requests as $req)
    <div class="card" style="margin-bottom:12px; display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap;">
        <div style="flex:1; min-width:0;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
                <span style="font-weight:700; font-size:15px; color:var(--ink);">{{ $req->reference }}</span>
                @if ($req->isOverdue())
                    <span class="pill pill-rejected">Overdue</span>
                @else
                    <span class="pill pill-pending">{{ ($isAdminDesk ?? false) ? 'Awaiting notary' : 'Awaiting you' }}</span>
                @endif
            </div>
            <div class="text-sm muted" style="margin-bottom:4px;">
                {{ $req->service->service_type }} &nbsp;·&nbsp; {{ $req->client->full_name }}
                @if ($isAdminDesk ?? false)
                    &nbsp;·&nbsp; booked with {{ $req->notary?->user?->full_name ?? 'no notary' }}
                @endif
            </div>
            <div class="text-sm" style="display:flex; align-items:center; gap:5px; color:var(--ink); margin-bottom:4px;">
                <x-heroicon-o-calendar style="width:13px;height:13px;"/>
                {{ $req->session?->scheduled_start_at?->format('l, j M · g:i A') ?? 'No fixed time — agree one with the client' }}
            </div>
            {{-- The clock is informational. Nothing is reassigned when it runs
                 out; it only says how long the client has been waiting. --}}
            <div class="text-sm" style="display:flex; align-items:center; gap:5px; color:{{ $req->isOverdue() ? 'var(--danger)' : 'var(--muted)' }};">
                <x-heroicon-o-clock style="width:13px;height:13px;"/>
                @if ($req->submitted_at)
                    Uploaded {{ $req->submitted_at->diffForHumans() }} &nbsp;·&nbsp;
                @endif
                Paid {{ $req->paid_at?->diffForHumans() ?? '—' }}
                @if ($req->fallback_due_at)
                    &nbsp;·&nbsp; {{ $req->isOverdue() ? 'response window passed ' . $req->fallback_due_at->diffForHumans() : 'window closes ' . $req->fallback_due_at->format('g:i A') }}
                @endif
                &nbsp;·&nbsp; not yet notarized
            </div>
        </div>
        <a class="btn btn-ghost btn-sm" href="{{ route('notary.requests.show', $req) }}">Review &rarr;</a>
    </div>
    @empty
    <div style="background:var(--surface); border:2px dashed var(--line); border-radius:var(--radius-lg); padding:56px 24px; text-align:center; color:var(--muted);">
        <div style="color:var(--brand); opacity:.35; margin-bottom:14px;">
            <x-heroicon-o-inbox style="width:48px;height:48px;"/>
        </div>
        <p style="font-weight:600; color:var(--ink); margin-bottom:4px; font-size:15px;">No incoming requests right now</p>
        <small>
            @if ($isAdminDesk ?? false)
                Every paid request has been answered. Unanswered ones appear here the moment a client pays.
            @else
                New paid requests will appear here as clients book you.
            @endif
        </small>
    </div>
    @endforelse
</div>
@endsection
