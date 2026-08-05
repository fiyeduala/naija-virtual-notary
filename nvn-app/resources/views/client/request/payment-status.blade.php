@extends('layouts.app', ['title' => 'Payment status'])

@push('styles')
<style>:root { --page-w: 680px; }</style>
@endpush

@section('content')
@php $paid = in_array($request->status, [\App\Enums\RequestStatus::Paid, \App\Enums\RequestStatus::Accepted], true); @endphp

<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('client.dashboard') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to dashboard
        </a>
        <h1>Payment status</h1>
        <div class="sub">{{ $paid ? 'Your payment has been confirmed' : 'Confirming your payment…' }}</div>
    </div>
</div>

<div class="shell">
    <div class="card">
        @if ($paid)
            <div style="text-align:center; padding:12px 0 24px;">
                <div style="width:64px; height:64px; background:var(--success-bg); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
                    <x-heroicon-o-check-circle style="width:34px;height:34px; color:var(--success);"/>
                </div>
                <div style="font-size:18px; font-weight:800; color:var(--ink); margin-bottom:6px;">Payment received</div>
                <p class="text-sm muted">Your notarization request <strong style="color:var(--ink); font-family:monospace;">{{ $request->reference }}</strong> is confirmed.</p>
            </div>
            <div style="border-top:1px solid var(--line); padding-top:18px; display:flex; flex-direction:column; gap:8px;">
                <p class="text-sm muted">Your notary has been notified. You'll get an email once they accept — or, if they're unavailable, we'll handle it for you so you're not kept waiting.</p>
                <div style="display:flex; align-items:center; gap:7px; font-size:14px; font-weight:500; color:var(--ink); margin-top:4px;">
                    <x-heroicon-o-calendar style="width:15px;height:15px; color:var(--brand);"/>
                    {{ $request->session?->scheduled_start_at?->format('l, j M Y · g:i A') ?? 'As soon as possible — your notary will confirm a time' }}
                </div>
                <a class="btn" href="{{ route('client.dashboard') }}" style="margin-top:12px; justify-content:center;">
                    Go to dashboard
                </a>
            </div>
        @else
            <div style="text-align:center; padding:12px 0 24px;">
                <div style="width:64px; height:64px; background:var(--warning-bg); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
                    <x-heroicon-o-clock style="width:34px;height:34px; color:var(--warning);"/>
                </div>
                <div style="font-size:18px; font-weight:800; color:var(--ink); margin-bottom:6px;">Confirming payment</div>
                <p class="text-sm muted">We're confirming your payment. This can take a moment. This page will reflect the update shortly, or check your dashboard.</p>
            </div>
            <div style="border-top:1px solid var(--line); padding-top:18px; display:flex; gap:10px; flex-wrap:wrap;">
                <a class="btn btn-ghost" href="{{ route('client.request.payment.status', $request) }}" style="flex:1; justify-content:center;">
                    <x-heroicon-o-arrow-path style="width:15px;height:15px;"/>
                    Refresh
                </a>
                <a class="btn" href="{{ route('client.dashboard') }}" style="flex:1; justify-content:center;">
                    Go to dashboard
                </a>
            </div>
        @endif
    </div>
</div>
@endsection
