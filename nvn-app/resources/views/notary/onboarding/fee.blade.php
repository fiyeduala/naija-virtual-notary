@extends('layouts.app', ['title' => 'Onboarding fee'])

@push('styles')
<style>:root { --page-w: 680px; }</style>
@endpush

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('notary.dashboard') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to dashboard
        </a>
        <h1>Onboarding fee</h1>
        <div class="sub">One-time payment to activate your partner application</div>
    </div>
</div>

<div class="shell">
    <div class="card">
        <div style="background:var(--brand-light); border:1px solid rgba(84,180,53,.25); border-radius:var(--radius); padding:20px 24px; margin-bottom:20px;">
            <div style="font-size:12px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px;">Amount due</div>
            <div style="font-size:38px; font-weight:800; color:var(--brand-dark); line-height:1;">{{ $amountDisplay }}</div>
        </div>

        <p style="font-size:14px; color:var(--ink); margin-bottom:8px;">To activate your partner application a one-time onboarding fee is required. Your credentials will be reviewed once payment is confirmed.</p>
        <p class="text-sm muted" style="margin-bottom:24px;">You'll be taken to Paystack's secure checkout page, then returned here automatically.</p>

        <form method="POST" action="{{ route('notary.onboarding.pay') }}">
            @csrf
            <button class="btn btn-block btn-lg" type="submit" style="justify-content:center;">
                <x-heroicon-o-credit-card style="width:18px;height:18px;"/>
                Pay with Paystack
            </button>
        </form>
    </div>
</div>
@endsection
