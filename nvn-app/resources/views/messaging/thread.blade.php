@extends('layouts.app', ['title' => 'Messages'])

@push('styles')
<style>:root { --page-w: 760px; }</style>
@endpush

@section('content')
@php
    $counterparty = auth()->user()->isClient()
        ? ($request->notary?->user?->full_name ?? 'Naija Virtual Notary')
        : $request->client->full_name;
@endphp

<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ $backRoute }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to dashboard
        </a>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <h1>Messages</h1>
            <span class="pill" style="background:rgba(255,255,255,.12); color:#fff; font-family:monospace;">{{ $request->reference }}</span>
        </div>
        <div class="sub">Conversation with {{ $counterparty }}</div>
    </div>
</div>

<div class="shell">
    <div class="card chat">
        <div class="chat-scroll" id="chat-scroll">
            @include('messaging._bubbles', ['messages' => $messages])
        </div>

        <form method="POST" action="{{ route('messages.store', $request) }}" class="chat-compose">
            @csrf
            <label for="body" class="muted text-sm">Your message</label>
            <textarea id="body" name="body" required placeholder="Type your message…"></textarea>
            <div class="chat-compose-row">
                <p class="text-sm muted chat-hint">{{ $counterparty }} is notified by email.</p>
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
    // Open on the newest message, the way every other chat behaves.
    const el = document.getElementById('chat-scroll');
    if (el) el.scrollTop = el.scrollHeight;
</script>
@endpush
