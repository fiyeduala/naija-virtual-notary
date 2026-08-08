@extends('layouts.app', ['title' => 'Review request'])

@push('styles')
<style>:root { --page-w: 780px; }</style>
@endpush

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('notary.requests.incoming') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to incoming
        </a>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <h1>{{ $request->reference }}</h1>
            <span class="pill">{{ $request->status->label() }}</span>
        </div>
    </div>
</div>

<div class="shell">

    {{-- Details --}}
    <div class="card">
        <h2 style="margin-bottom:16px;">Details</h2>
        <div style="display:flex; flex-direction:column; gap:0;">
            <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--line);">
                <span class="text-sm muted">Client</span>
                <span class="text-sm" style="font-weight:500;">{{ $request->client->full_name }}</span>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--line);">
                <span class="text-sm muted">Service</span>
                <span class="text-sm" style="font-weight:500;">{{ $request->service->service_type }}</span>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--line);">
                <span class="text-sm muted">Fee</span>
                <span class="text-sm" style="font-weight:600; color:var(--brand-dark);">
                    {{ $request->displayFee() }}
                    {{-- The whole job, not the price of one document. A notary
                         quoting from this line has to see what the client paid. --}}
                    @if (($count = $request->billableDocumentCount()) > 1)
                        <span class="muted" style="font-weight:400;">
                            ({{ $request->service->displayPrice($request->currency) }} &times; {{ $count }} documents)
                        </span>
                    @endif
                </span>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; gap:14px; padding:10px 0; border-bottom:1px solid var(--line);">
                <span class="text-sm muted">Scheduled</span>
                <span class="text-sm" style="font-weight:500; text-align:right;">
                    {{ $request->session?->scheduled_start_at?->format('l, j M Y · g:i A') ?? 'No fixed time — agree one with the client' }}
                </span>
            </div>
            <div style="padding:10px 0;">
                <span class="text-sm muted">Reason</span>
                <p class="text-sm" style="margin-top:5px; color:var(--ink);">{{ $request->document_use }}</p>
            </div>
        </div>
    </div>

    {{-- Documents --}}
    <div class="card" style="margin-top:16px;">
        <h2 style="margin-bottom:6px;">Documents</h2>
        <p class="text-sm muted" style="margin-bottom:14px;">
            Open each file before you decide — you are accepting responsibility for what is in them.
        </p>
        @foreach ($request->documents as $doc)
            <div style="display:flex; justify-content:space-between; align-items:center; gap:14px; padding:9px 0; border-bottom:1px solid var(--line);">
                <div style="min-width:0;">
                    <div class="text-sm" style="font-weight:500;">{{ str_replace('_', ' ', ucfirst($doc->file_type)) }}</div>
                    <div class="text-sm muted" style="word-break:break-word;">{{ $doc->original_filename }}</div>
                </div>
                <a class="btn btn-ghost btn-sm" style="flex-shrink:0;"
                   href="{{ route('notary.requests.document', [$request, $doc]) }}" target="_blank" rel="noopener">
                    <x-heroicon-o-eye style="width:14px;height:14px;"/>
                    View
                </a>
            </div>
        @endforeach
        <p class="text-sm muted" style="margin-top:12px;">
            Files open in a new tab and are streamed from private storage — they are never publicly linkable.
        </p>
    </div>

    {{-- Messages --}}
    <div class="card" style="margin-top:16px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div style="min-width:0;">
            <h2 style="margin-bottom:4px;">Messages</h2>
            <p class="text-sm muted" style="margin:0;">
                Ask the client a question, or agree a time if none was fixed.
            </p>
        </div>
        <a class="btn btn-ghost btn-sm" style="flex-shrink:0;" href="{{ route('messages.show', $request) }}">
            <x-heroicon-o-chat-bubble-left-right style="width:14px;height:14px;"/>
            Open thread
        </a>
    </div>

    {{-- Accept / Decline (Paid status) --}}
    @if ($request->status === \App\Enums\RequestStatus::Paid)
    <div class="card" style="margin-top:16px;">
        <h2 style="margin-bottom:16px;">Respond to this request</h2>
        <form method="POST" action="{{ route('notary.requests.accept', $request) }}">
            @csrf
            <button class="btn btn-block" type="submit" style="justify-content:center;">
                <x-heroicon-o-check style="width:16px;height:16px;"/>
                Accept request
            </button>
        </form>
        <div style="border-top:1px solid var(--line); margin-top:20px; padding-top:20px;">
            <p style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin-bottom:12px;">Or decline</p>
            <form method="POST" action="{{ route('notary.requests.decline', $request) }}">
                @csrf
                <label for="reason">Reason for declining (optional)</label>
                <textarea id="reason" name="reason" style="min-height:72px;"></textarea>
                <div style="display:flex; align-items:center; justify-content:space-between; margin-top:12px; flex-wrap:wrap; gap:10px;">
                    <p class="text-sm muted" style="margin:0;">Declining reassigns this request so the client isn't left waiting.</p>
                    <button class="btn btn-ghost btn-sm" type="submit">Decline</button>
                </div>
            </form>
        </div>
    </div>
    @endif

    {{-- Session (Accepted / In progress) --}}
    @if (in_array($request->status, [\App\Enums\RequestStatus::Accepted, \App\Enums\RequestStatus::InVerification, \App\Enums\RequestStatus::Notarizing], true))
    <div class="card" style="margin-top:16px;">
        <h2 style="margin-bottom:6px;">Start this session</h2>
        <p class="text-sm muted" style="margin-bottom:20px;">Choose how you want to verify the client's identity for this notarization.</p>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="{{ route('session.join', $request) }}" class="btn" style="flex:1; text-align:center; justify-content:center;">
                <x-heroicon-o-video-camera style="width:16px;height:16px;"/>
                Join verification call
            </a>
            <form method="POST" action="{{ route('session.skip-call', $request) }}" style="flex:1;">
                @csrf
                <button class="btn btn-ghost btn-block" type="submit" style="justify-content:center;">
                    Skip call — uploaded ID is sufficient
                </button>
            </form>
        </div>
        <p class="muted text-sm" style="margin-top:12px;">
            Joining the call lets you verify the client's ID live on camera.
            Skipping goes straight to the document editor — use this when you're satisfied the uploaded ID is adequate.
        </p>
    </div>
    @endif

</div>
@endsection
