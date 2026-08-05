@extends('layouts.app', ['title' => 'Notary applications'])

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
        <h1>Notary applications</h1>
        <div class="sub">Applications that have paid the onboarding fee and are awaiting review.</div>
    </div>
</div>

<div class="shell">
    @forelse ($pending as $notary)
        <div class="card" style="margin-bottom:12px; display:flex; justify-content:space-between; align-items:center; gap:16px;">
            <div style="min-width:0;">
                <div style="font-weight:700; font-size:15px;">{{ $notary->user->full_name }}</div>
                <div class="muted text-sm" style="margin-top:2px;">
                    {{ $notary->user->email }}
                    &nbsp;·&nbsp; {{ ucfirst($notary->entity_type) }}
                    &nbsp;·&nbsp; Ref {{ $notary->license_ref }}
                </div>
            </div>
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.notaries.show', $notary) }}" style="flex-shrink:0;">Review</a>
        </div>
    @empty
        <div class="card" style="text-align:center; padding:36px 26px;">
            <p class="muted">No applications awaiting review.</p>
        </div>
    @endforelse
</div>
@endsection
