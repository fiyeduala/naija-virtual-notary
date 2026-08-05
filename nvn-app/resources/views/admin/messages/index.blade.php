@extends('layouts.app', ['title' => 'All message threads'])

@push('styles')
<style>:root { --page-w: 860px; }</style>
@endpush

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('filament.admin.pages.dashboard') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Admin panel
        </a>
        <h1>Message threads</h1>
        <div class="sub">Every conversation between clients and notaries — open any thread to read it or step in.</div>
    </div>
</div>

<div class="shell">

    <form method="GET" action="{{ route('admin.messages.index') }}" class="card">
        <label for="q" style="margin-top:0;">Search by reference or client name</label>
        <div style="display:flex; gap:10px; align-items:center;">
            <input id="q" type="text" name="q" value="{{ $q }}" placeholder="NVN-2026-… or client name">
            <button class="btn btn-ghost" type="submit" style="flex-shrink:0;">Search</button>
        </div>
    </form>

    @forelse ($threads as $req)
        <div class="card" style="margin-top:12px; display:flex; justify-content:space-between; align-items:center; gap:16px;">
            <div style="min-width:0;">
                <div style="font-weight:700; font-size:15px; font-family:monospace;">{{ $req->reference }}</div>
                <div class="muted text-sm" style="margin-top:2px;">
                    {{ $req->client->full_name }}
                    @if ($req->notary) &nbsp;·&nbsp; {{ $req->notary->user->full_name }} @endif
                    &nbsp;·&nbsp; {{ $req->messages_count }} message{{ $req->messages_count === 1 ? '' : 's' }}
                </div>
            </div>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.messages.show', $req) }}" style="flex-shrink:0;">Open</a>
        </div>
    @empty
        <div class="card" style="margin-top:12px; text-align:center; padding:36px 26px;">
            <p class="muted">No message threads yet.</p>
        </div>
    @endforelse

    <div style="margin-top:16px;">{{ $threads->withQueryString()->links() }}</div>

</div>
@endsection
