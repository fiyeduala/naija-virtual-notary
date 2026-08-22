@extends('layouts.app', ['title' => 'Offsite notarization'])

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('notary.dashboard') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to dashboard
        </a>
        <h1>Offsite notarization</h1>
        <div class="sub">
            Took a notarization on outside the platform? Upload the document, pay {{ $fee }} per document,
            and place your signature, stamp and seal here. The sealed file is yours to download and hand over.
        </div>
    </div>
</div>

<div class="shell">

    @if ($blocked)
    <div class="alert alert-error" style="display:flex; gap:10px; align-items:flex-start; margin-bottom:18px;">
        <x-heroicon-o-exclamation-triangle style="width:18px;height:18px;flex:none;margin-top:1px;"/>
        <div>
            <div style="font-weight:600; margin-bottom:2px;">You cannot start an offsite job yet</div>
            {{ $blocked }}
            @if ($profile && $profile->verification_status === 'approved')
                <div style="margin-top:8px;">
                    <a href="{{ route('notary.profile.edit') }}" class="btn btn-ghost btn-sm">Upload them now</a>
                </div>
            @endif
        </div>
    </div>
    @else
    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div class="text-sm muted">
            {{ $fee }} per document, charged once, when you are ready to seal.
        </div>
        <a href="{{ route('notary.offsite.create') }}" class="btn">
            <x-heroicon-o-plus style="width:15px;height:15px;"/>
            New offsite job
        </a>
    </div>
    @endif

    @forelse ($jobs as $job)
    <div class="card" style="margin-bottom:12px; display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap;">
        <div style="flex:1; min-width:0;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
                <span style="font-weight:700; font-size:15px; color:var(--ink);">{{ $job->reference }}</span>
                @if ($job->finalDocuments->isNotEmpty())
                    <span class="pill pill-approved">Sealed</span>
                @elseif ($job->status === \App\Enums\RequestStatus::Draft)
                    <span class="pill pill-pending">Awaiting payment</span>
                @else
                    <span class="pill pill-approved">Paid — ready to seal</span>
                @endif
            </div>
            <div class="text-sm" style="color:var(--ink); margin-bottom:4px;">
                {{ $job->document_use ?: 'No description' }}
            </div>
            <div class="text-sm muted">
                {{ $job->notarizable_documents_count }}
                {{ \Illuminate\Support\Str::plural('document', $job->notarizable_documents_count) }}
                &nbsp;·&nbsp; {{ $job->displayFee() }}
                &nbsp;·&nbsp; started {{ $job->created_at->diffForHumans() }}
            </div>
        </div>
        <a class="btn btn-ghost btn-sm" href="{{ route('notary.offsite.show', $job) }}">
            {{ $job->status === \App\Enums\RequestStatus::Draft ? 'Pay and seal' : 'Open' }} &rarr;
        </a>
    </div>
    @empty
    <div style="background:var(--surface); border:2px dashed var(--line); border-radius:var(--radius-lg); padding:56px 24px; text-align:center; color:var(--muted);">
        <div style="color:var(--brand); opacity:.35; margin-bottom:14px;">
            <x-heroicon-o-briefcase style="width:48px;height:48px;"/>
        </div>
        <p style="font-weight:600; color:var(--ink); margin-bottom:4px; font-size:15px;">No offsite jobs yet</p>
        <small>
            When you take a notarization on yourself — at your office, at a client's — bring the
            document here to seal it digitally. The platform does nothing else: no client account,
            no appointment, no commission on what you charged them.
        </small>
    </div>
    @endforelse

    @if ($jobs->hasPages())
    <div style="margin-top:18px;">{{ $jobs->links() }}</div>
    @endif

</div>
@endsection
