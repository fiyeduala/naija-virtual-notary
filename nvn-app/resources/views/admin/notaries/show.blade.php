@extends('layouts.app', ['title' => 'Review application'])

@push('styles')
<style>:root { --page-w: 780px; }</style>
@endpush

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('admin.notaries.index') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            All applications
        </a>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <h1>{{ $notary->user->full_name }}</h1>
            <span class="pill pill-{{ $notary->verification_status }}">{{ ucfirst($notary->verification_status) }}</span>
        </div>
        <div class="sub">{{ $notary->user->email }} &nbsp;·&nbsp; {{ ucfirst($notary->entity_type) }}</div>
    </div>
</div>

<div class="shell">

    {{-- Details --}}
    <div class="card">
        <h2 style="margin-bottom:14px;">Details</h2>
        @php
            $details = [
                'Email'         => $notary->user->email,
                'Phone'         => $notary->user->phone,
                'Type'          => ucfirst($notary->entity_type) . ($notary->organization_name ? ' — ' . $notary->organization_name : ''),
                'License ref'   => $notary->license_ref,
                'Year of oath'  => $notary->year_of_oath,
                'Specialties'   => collect($notary->specialties)->filter()->implode(', ') ?: '—',
            ];
        @endphp
        @foreach ($details as $label => $value)
            <div style="display:flex; justify-content:space-between; gap:16px; padding:9px 0; border-bottom:1px solid var(--line);">
                <span class="text-sm muted" style="flex-shrink:0;">{{ $label }}</span>
                <span class="text-sm" style="font-weight:500; text-align:right;">{{ $value }}</span>
            </div>
        @endforeach

        <div style="padding-top:14px;">
            <div class="text-sm muted" style="margin-bottom:4px;">Experience</div>
            <p class="text-sm" style="margin-bottom:14px;">{{ $notary->experience }}</p>
            <div class="text-sm muted" style="margin-bottom:4px;">Motivation</div>
            <p class="text-sm">{{ $notary->motivation }}</p>
        </div>
    </div>

    {{-- Credentials --}}
    <div class="card" style="margin-top:16px;">
        <h2 style="margin-bottom:14px;">Uploaded credentials</h2>
        @forelse ($notary->credentials as $cred)
            <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; padding:10px 0; border-bottom:1px solid var(--line);">
                <div style="min-width:0;">
                    <div class="text-sm" style="font-weight:500;">{{ str_replace('_', ' ', ucfirst($cred->document_type)) }}</div>
                    <div class="text-sm muted" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $cred->original_filename }}</div>
                </div>
                <a class="btn btn-ghost btn-sm" href="{{ route('admin.credentials.download', $cred) }}" style="flex-shrink:0;">
                    <x-heroicon-o-arrow-down-tray style="width:14px;height:14px;"/>
                    Download
                </a>
            </div>
        @empty
            <p class="muted text-sm">No credentials uploaded.</p>
        @endforelse
        <p class="muted text-sm" style="margin-top:12px;">Files are stored on the private disk and served only through this authenticated link.</p>
    </div>

    {{-- Decision --}}
    <form method="POST" action="{{ route('admin.notaries.approve', $notary) }}" style="margin-top:16px;">
        @csrf
        <button class="btn btn-block btn-lg" type="submit">
            <x-heroicon-o-check-circle style="width:18px;height:18px;"/>
            Approve application
        </button>
    </form>

    <form method="POST" action="{{ route('admin.notaries.reject', $notary) }}" class="card" style="margin-top:16px;">
        @csrf
        <label for="notes" style="margin-top:0;">Reason for rejection (sent to the applicant)</label>
        <textarea id="notes" name="notes" required placeholder="Explain what was missing or incorrect…"></textarea>
        <button class="btn btn-ghost" type="submit" style="margin-top:12px;">Reject application</button>
    </form>

</div>
@endsection
