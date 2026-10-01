@extends('layouts.app', ['title' => 'Completed requests'])

{{--
    Finished work, newest first.

    The sealed documents are offered right here rather than only behind the
    request, because "send me that notarized copy again" is the one reason
    anybody opens this screen. Each link streams from private storage through
    notary.requests.document, which checks the file belongs to the request and
    writes an audit entry — the same path the review screen uses.
--}}

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('notary.dashboard') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to dashboard
        </a>
        <h1>Completed requests</h1>
        <div class="sub">
            @if ($isAdminDesk ?? false)
                Every request you notarized or took over, newest first — including the ones you
                covered for a partner. Offsite jobs have their own screen.
            @else
                Every request you have notarized, newest first, with the sealed documents you issued.
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
                <span class="pill pill-approved">Completed</span>
            </div>
            <div class="text-sm muted" style="margin-bottom:4px;">
                {{ $req->service?->service_type ?? 'No category' }} &nbsp;·&nbsp; {{ $req->client?->full_name ?? 'client removed' }}
                @if (($isAdminDesk ?? false) && $req->notary && ! $req->notary->is_system_native)
                    &nbsp;·&nbsp; sealed for {{ $req->notary->user?->full_name }}
                @endif
            </div>
            <div class="text-sm" style="display:flex; align-items:center; gap:5px; color:var(--muted);">
                <x-heroicon-o-check-badge style="width:13px;height:13px;"/>
                Completed {{ $req->completed_at?->format('j M Y · g:i A') ?? '—' }}
                @if ($req->completed_at)
                    &nbsp;·&nbsp; {{ $req->completed_at->diffForHumans() }}
                @endif
            </div>

            {{-- One row per sealed output: a request with additional documents
                 produces one PDF each, and the notary needs the right one. --}}
            @if ($req->finalDocuments->isNotEmpty())
            <div style="margin-top:10px; display:flex; flex-wrap:wrap; gap:8px;">
                @foreach ($req->finalDocuments as $doc)
                    <a class="btn btn-ghost btn-sm"
                       href="{{ route('notary.requests.document', [$req, $doc]) }}" target="_blank" rel="noopener">
                        <x-heroicon-o-document-check style="width:14px;height:14px;"/>
                        {{ \Illuminate\Support\Str::limit($doc->original_filename ?? 'Sealed document', 28) }}
                    </a>
                @endforeach
            </div>
            @else
            {{-- Completed without a sealed file means it was closed by hand, so
                 say so rather than showing nothing and leaving it a mystery. --}}
            <div class="text-sm" style="margin-top:8px; color:var(--muted);">
                No sealed document on this one — it was completed without one.
            </div>
            @endif
        </div>
        <a class="btn btn-ghost btn-sm" style="flex-shrink:0;" href="{{ route('notary.requests.show', $req) }}">View &rarr;</a>
    </div>
    @empty
    <div style="background:var(--surface); border:2px dashed var(--line); border-radius:var(--radius-lg); padding:56px 24px; text-align:center; color:var(--muted);">
        <div style="color:var(--brand); opacity:.35; margin-bottom:14px;">
            <x-heroicon-o-check-badge style="width:48px;height:48px;"/>
        </div>
        <p style="font-weight:600; color:var(--ink); margin-bottom:4px; font-size:15px;">Nothing completed yet</p>
        <small>A request appears here once you have sealed it and closed the session.</small>
    </div>
    @endforelse

    @if ($requests->hasPages())
        <div style="margin-top:18px;">{{ $requests->links() }}</div>
    @endif
</div>
@endsection
