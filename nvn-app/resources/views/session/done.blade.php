@extends('layouts.app', ['title' => 'Notarization complete'])

@push('styles')
<style>
.done-wrap { max-width: 620px; margin: 0 auto; }

.done-hero {
    text-align: center;
    padding: 34px 26px 28px;
    border-bottom: 1px solid var(--line);
}
.done-check {
    width: 58px; height: 58px;
    margin: 0 auto 16px;
    border-radius: 50%;
    background: var(--success-bg);
    border: 1px solid var(--success-line);
    display: flex; align-items: center; justify-content: center;
    color: var(--success);
}
.done-hero h1 { font-size: 21px; margin-bottom: 7px; }
.done-hero p  { font-size: 13.5px; color: var(--muted); line-height: 1.6; max-width: 400px; margin: 0 auto; }

.done-meta { padding: 18px 26px; }
.meta-row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 9px 0;
    font-size: 13px;
    border-bottom: 1px solid var(--line);
}
.meta-row:last-child { border-bottom: none; }
.meta-row dt { color: var(--muted); flex-shrink: 0; }
.meta-row dd { font-weight: 500; text-align: right; word-break: break-word; }

.done-actions {
    padding: 18px 26px 22px;
    border-top: 1px solid var(--line);
    background: #fcfdfc;
    border-radius: 0 0 var(--radius-lg) var(--radius-lg);
    display: grid;
    gap: 9px;
}
.done-actions .row { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }
@media (max-width: 500px) { .done-actions .row { grid-template-columns: 1fr; } }
.done-actions .btn { justify-content: center; }

.fallback-note {
    display: flex;
    gap: 9px;
    align-items: flex-start;
    margin: 0 26px 18px;
    padding: 11px 13px;
    border-radius: var(--radius-sm);
    background: var(--warning-bg);
    font-size: 12.5px;
    color: var(--warning);
    line-height: 1.5;
}
.fallback-note svg { flex-shrink: 0; margin-top: 1px; }
</style>
@endpush

@section('content')
@php
    $user = auth()->user();

    // notary.requests.show is scoped to the assigned notary — an admin handling a
    // fallback has to go through the Filament record instead.
    $isAssignedNotary = $user->notaryProfile && $request->notary_id === $user->notaryProfile->id;

    // `role` is a UserRole enum — a string comparison here is always false.
    $dashboardRoute = $user->isNotary()
        ? route('notary.dashboard')
        : route('filament.admin.pages.dashboard');
    $requestRoute   = $isAssignedNotary
        ? route('notary.requests.show', $request)
        : route('filament.admin.resources.notarization-requests.view', ['record' => $request->id]);
@endphp

<div class="shell">
    <div class="done-wrap">
        <div class="card" style="padding:0; overflow:hidden;">

            <div class="done-hero">
                <div class="done-check">
                    <x-heroicon-o-check-badge style="width:30px;height:30px;"/>
                </div>
                <h1>Notarization complete</h1>
                <p>The document has been sealed and the client notified. It is now available in their dashboard.</p>
            </div>

            <div class="done-meta">
                <dl style="margin:0;">
                    <div class="meta-row">
                        <dt>Reference</dt>
                        <dd>{{ $request->reference }}</dd>
                    </div>
                    <div class="meta-row">
                        <dt>Client</dt>
                        <dd>{{ $request->client->full_name }}</dd>
                    </div>
                    @if ($request->service)
                    <div class="meta-row">
                        <dt>Service</dt>
                        <dd>{{ $request->service->service_type }}</dd>
                    </div>
                    @endif
                    <div class="meta-row">
                        <dt>Notarized by</dt>
                        <dd>{{ $request->notary?->user?->full_name ?? 'Naija Virtual Notary' }}</dd>
                    </div>
                    <div class="meta-row">
                        <dt>Completed</dt>
                        <dd>{{ ($request->completed_at ?? now())->format('j M Y · g:i A') }}</dd>
                    </div>
                    <div class="meta-row">
                        <dt>Status</dt>
                        <dd><span class="pill pill-approved">Completed</span></dd>
                    </div>
                </dl>
            </div>

            {{-- Internal note: only the notary side reaches this screen. The
                 client's copy of the document names the notary they booked. --}}
            @if ($request->isCoveredByPlatform())
                <div class="fallback-note">
                    <x-heroicon-o-information-circle style="width:15px;height:15px;"/>
                    <span>
                        Completed by the platform on behalf of
                        {{ $request->notary?->user?->full_name ?? 'the assigned notary' }},
                        under their signature, stamp and seal.
                    </span>
                </div>
            @endif

            <div class="done-actions">
                <a class="btn" href="{{ route('client.documents.download', $request) }}">
                    <x-heroicon-o-arrow-down-tray style="width:15px;height:15px;"/>
                    Download sealed document
                </a>
                <div class="row">
                    <a class="btn btn-ghost" href="{{ $requestRoute }}">
                        <x-heroicon-o-document-text style="width:15px;height:15px;"/>
                        View request
                    </a>
                    <a class="btn btn-ghost" href="{{ $dashboardRoute }}">
                        <x-heroicon-o-home style="width:15px;height:15px;"/>
                        Return to dashboard
                    </a>
                </div>
            </div>

        </div>

        <p class="muted text-sm" style="text-align:center; margin-top:14px; font-size:12.5px;">
            Check the sealed PDF before closing — your signature, stamp and seal should appear exactly where you placed them.
        </p>
    </div>
</div>
@endsection
