@extends('layouts.app', ['title' => $isRenewal ? 'Renew your membership' : 'Partner fee'])

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
        <h1>{{ $isRenewal ? 'Renew your membership' : 'Partner fee' }}</h1>
        <div class="sub">
            {{ $isRenewal
                ? 'Another year of taking bookings on the platform'
                : 'Yearly membership fee to activate your partner application' }}
        </div>
    </div>
</div>

<div class="shell">
    <div class="card">
        <div style="background:var(--brand-light); border:1px solid rgba(84,180,53,.25); border-radius:var(--radius); padding:20px 24px; margin-bottom:20px;">
            <div style="font-size:12px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px;">
                Amount due — 12 months
            </div>
            <div style="font-size:38px; font-weight:800; color:var(--brand-dark); line-height:1;">{{ $amountDisplay }}</div>
        </div>

        {{-- Renewing and joining are the same payment, but not the same news, so
             the page says which one is happening and what it changes. --}}
        @if ($isRenewal)
            @if ($profile?->membershipLapsed())
                <div class="alert alert-error" style="display:flex; align-items:flex-start; gap:10px; margin-bottom:16px;">
                    <x-heroicon-o-exclamation-triangle style="width:16px;height:16px;flex-shrink:0;margin-top:2px;"/>
                    <span>
                        Your membership ended on {{ $profile->membership_expires_at->format('j F Y') }},
                        so you are not currently listed in the marketplace and new clients cannot book you.
                        Renewing puts you straight back.
                    </span>
                </div>
            @elseif ($profile?->membership_expires_at)
                <div class="alert" style="background:var(--warning-bg); border:1px solid #e8c97a; color:var(--warning); display:flex; align-items:flex-start; gap:10px; margin-bottom:16px;">
                    <x-heroicon-o-clock style="width:16px;height:16px;flex-shrink:0;margin-top:2px;"/>
                    <span>
                        Your membership runs until {{ $profile->membership_expires_at->format('j F Y') }}
                        ({{ $profile->membershipDaysLeft() }} days). Paying now adds a year onto that date —
                        you do not lose the time you have left.
                    </span>
                </div>
            @endif
            <p style="font-size:14px; color:var(--ink); margin-bottom:8px;">
                Your membership fee covers twelve months of listing in the marketplace,
                taking bookings, and being paid out for the work you complete.
            </p>
        @else
            <p style="font-size:14px; color:var(--ink); margin-bottom:8px;">
                To activate your partner application a membership fee is required. It covers
                twelve months on the platform and renews yearly. Your credentials will be
                reviewed once payment is confirmed.
            </p>
        @endif

        <p class="text-sm muted" style="margin-bottom:24px;">
            You'll be taken to Paystack's secure checkout page, then returned here automatically.
            If you would rather pay by bank transfer, contact us and an administrator will record it.
        </p>

        <form method="POST" action="{{ route('notary.onboarding.pay') }}">
            @csrf
            <button class="btn btn-block btn-lg" type="submit" style="justify-content:center;">
                <x-heroicon-o-credit-card style="width:18px;height:18px;"/>
                {{ $isRenewal ? 'Renew for a year' : 'Pay with Paystack' }}
            </button>
        </form>
    </div>
</div>
@endsection
