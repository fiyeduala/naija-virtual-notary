@extends('layouts.app', ['title' => 'Review your request'])

@push('styles')
<style>:root { --page-w: 680px; }</style>
@endpush

@section('content')
@php
    $service = $request->service;
    $price   = $service?->displayPrice($request->currency);
@endphp

<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('client.marketplace.show', [$request, $request->notary]) }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Edit notary or time
        </a>
        <h1>Review your request</h1>
        <div class="sub">Confirm the details below, then proceed to payment</div>
    </div>
</div>

<div class="shell">

    {{-- Summary card --}}
    <div class="card">
        <h2 style="margin-bottom:16px;">Summary</h2>
        <div style="display:flex; flex-direction:column;">
            <div style="display:flex; justify-content:space-between; padding:9px 0; border-bottom:1px solid var(--line);">
                <span class="text-sm muted">Reference</span>
                <span class="text-sm" style="font-weight:500; font-family:monospace;">{{ $request->reference }}</span>
            </div>
            <div style="display:flex; justify-content:space-between; padding:9px 0; border-bottom:1px solid var(--line);">
                <span class="text-sm muted">Notary</span>
                <span class="text-sm" style="font-weight:500;">{{ $request->notary->user->full_name }}</span>
            </div>
            <div style="display:flex; justify-content:space-between; padding:9px 0; border-bottom:1px solid var(--line);">
                <span class="text-sm muted">Service</span>
                <span class="text-sm" style="font-weight:500;">{{ $service->service_type }}</span>
            </div>
            <div style="display:flex; justify-content:space-between; padding:9px 0; border-bottom:1px solid var(--line);">
                <span class="text-sm muted">Date &amp; time</span>
                <span class="text-sm" style="font-weight:500;">
                    {{ $request->session?->scheduled_start_at?->format('l, j M Y · g:i A') ?? 'As soon as possible' }}
                </span>
            </div>
            <div style="display:flex; justify-content:space-between; padding:9px 0; border-bottom:1px solid var(--line);">
                <span class="text-sm muted">Hard copy</span>
                <span class="text-sm" style="font-weight:500;">{{ $request->hard_copy_requested ? 'Yes' : 'No — email soft copy' }}</span>
            </div>
            <div style="display:flex; justify-content:space-between; padding:14px 0 2px;">
                <span style="font-weight:700; font-size:15px;">Total</span>
                <span style="font-weight:800; font-size:18px; color:var(--brand-dark);">{{ $price }}</span>
            </div>
        </div>
    </div>

    {{-- Documents --}}
    <div class="card" style="margin-top:16px;">
        <h2 style="margin-bottom:16px;">Documents</h2>
        @foreach ($request->documents as $doc)
            <div style="display:flex; justify-content:space-between; align-items:center; padding:9px 0; border-bottom:1px solid var(--line);">
                <span class="text-sm" style="font-weight:500;">{{ str_replace('_', ' ', ucfirst($doc->file_type)) }}</span>
                <span class="text-sm muted">{{ $doc->original_filename }}</span>
            </div>
        @endforeach
    </div>

    {{-- Pay button --}}
    <form method="POST" action="{{ route('client.request.payment.pay', $request) }}" style="margin-top:20px;">
        @csrf
        <button class="btn btn-block btn-lg" type="submit" style="justify-content:center;">
            <x-heroicon-o-credit-card style="width:18px;height:18px;"/>
            Pay {{ $price }} with Paystack
        </button>
    </form>
    <p class="text-sm muted" style="text-align:center; margin-top:10px;">
        Your selected notary is notified only after payment is confirmed.
    </p>

</div>
@endsection
