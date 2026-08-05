@extends('layouts.app', ['title' => 'Thread'])

@push('styles')
<style>:root { --page-w: 760px; }</style>
@endpush

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('admin.messages.index') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            All threads
        </a>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <h1>Thread</h1>
            <span class="pill" style="background:rgba(255,255,255,.12); color:#fff; font-family:monospace;">{{ $request->reference }}</span>
        </div>
        <div class="sub">
            Client: {{ $request->client->full_name }}
            @if ($request->notary) &nbsp;·&nbsp; Notary: {{ $request->notary->user->full_name }} @endif
            @if ($request->handledBy) &nbsp;·&nbsp; Handled by: {{ $request->handledBy->full_name }} @endif
        </div>
    </div>
</div>

<div class="shell">
    <div class="card chat">
        <div class="chat-scroll" id="chat-scroll">
            @include('messaging._bubbles', ['messages' => $messages])
        </div>

        <form method="POST" action="{{ route('admin.messages.store', $request) }}" class="chat-compose">
            @csrf
            <label for="body" class="muted text-sm">Reply as Naija Virtual Notary (Support)</label>
            <textarea id="body" name="body" required placeholder="Step into the conversation…"></textarea>
            <div class="chat-compose-row">
                <p class="text-sm muted chat-hint">The client sees this as Naija Virtual Notary (Support).</p>
                <button class="btn" type="submit">
                    <x-heroicon-o-paper-airplane style="width:15px;height:15px;"/>
                    Send
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const el = document.getElementById('chat-scroll');
    if (el) el.scrollTop = el.scrollHeight;
</script>
@endpush
