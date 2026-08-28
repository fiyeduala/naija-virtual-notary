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
            @if ($isAdmin)
                Every offsite job on the platform. Place one yourself when a client pays the desk
                directly — under your own marks, or under a partner's for work they did outside and
                sent in — then record what the client paid and seal it here.
            @else
                Took a notarization on outside the platform? Upload the document, pay {{ $fee }} per document,
                and place your signature, stamp and seal here. The sealed file is yours to download and hand over.
            @endif
        </div>
    </div>
</div>

<div class="shell">

    @if ($blocked)
    <div class="alert alert-error" style="display:flex; gap:10px; align-items:flex-start; margin-bottom:18px;">
        <x-heroicon-o-exclamation-triangle style="width:18px;height:18px;flex:none;margin-top:1px;"/>
        <div>
            <div style="font-weight:600; margin-bottom:2px;">
                {{ $isAdmin ? 'There is nobody to seal in the name of yet' : 'You cannot start an offsite job yet' }}
            </div>
            {{ $blocked }}
            @if (! $isAdmin && $profile && $profile->verification_status === 'approved')
                <div style="margin-top:8px;">
                    <a href="{{ route('notary.profile.edit') }}" class="btn btn-ghost btn-sm">Upload them now</a>
                </div>
            @endif
        </div>
    </div>
    @else
    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div class="text-sm muted">
            @if ($isAdmin)
                Partners are charged {{ $fee }} per document. A job you place is not — you record
                what the client paid instead.
            @else
                {{ $fee }} per document, charged once, when you are ready to seal.
            @endif
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
                    <span class="pill pill-pending">{{ $isAdmin ? 'Not opened yet' : 'Awaiting payment' }}</span>
                @else
                    <span class="pill pill-approved">{{ $isAdmin ? 'Open — ready to seal' : 'Paid — ready to seal' }}</span>
                @endif
            </div>
            <div class="text-sm" style="color:var(--ink); margin-bottom:4px;">
                {{ $job->document_use ?: 'No description' }}
            </div>
            <div class="text-sm muted">
                {{ $job->notarizable_documents_count }}
                {{ \Illuminate\Support\Str::plural('document', $job->notarizable_documents_count) }}
                @php $paid = $job->amountPaidMinor(); @endphp
                &nbsp;·&nbsp; {{ $paid > 0
                    ? \App\Models\NotarizationRequest::money($paid, $job->currency ?: 'NGN')
                    : $job->displayFee() }}
                {{-- Whose seal, only where it is not already obvious: a notary's
                     own list has exactly one answer on every row. --}}
                @if ($isAdmin)
                    &nbsp;·&nbsp; {{ $job->notary?->user?->full_name ?? 'unassigned' }}
                @endif
                &nbsp;·&nbsp; started {{ $job->created_at->diffForHumans() }}
            </div>
        </div>
        <a class="btn btn-ghost btn-sm" href="{{ route('notary.offsite.show', $job) }}">
            @if ($job->status !== \App\Enums\RequestStatus::Draft)
                Open
            @else
                {{ $isAdmin ? 'Record and seal' : 'Pay and seal' }}
            @endif
            &rarr;
        </a>
    </div>
    @empty
    <div style="background:var(--surface); border:2px dashed var(--line); border-radius:var(--radius-lg); padding:56px 24px; text-align:center; color:var(--muted);">
        <div style="color:var(--brand); opacity:.35; margin-bottom:14px;">
            <x-heroicon-o-briefcase style="width:48px;height:48px;"/>
        </div>
        <p style="font-weight:600; color:var(--ink); margin-bottom:4px; font-size:15px;">No offsite jobs yet</p>
        <small>
            @if ($isAdmin)
                Offsite work arrives two ways: a partner pays {{ $fee }} a document to seal a job
                they took on themselves, or a client pays the desk directly and you place the job
                here. Nothing is scheduled and no client account is created either way.
            @else
                When you take a notarization on yourself — at your office, at a client's — bring the
                document here to seal it digitally. The platform does nothing else: no client account,
                no appointment, no commission on what you charged them.
            @endif
        </small>
    </div>
    @endforelse

    @if ($jobs->hasPages())
    <div style="margin-top:18px;">{{ $jobs->links() }}</div>
    @endif

</div>
@endsection
