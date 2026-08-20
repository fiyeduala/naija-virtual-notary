@extends('layouts.app', ['title' => 'Application status'])

@push('styles')
<style>:root { --page-w: 680px; }</style>
@endpush

@section('content')
@php
    $status  = $profile->verification_status;
    $feePaid = (bool) $profile->onboarding_fee_paid_at;
@endphp

<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('notary.dashboard') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to dashboard
        </a>
        <h1>Application status</h1>
        <div class="sub">Track your onboarding progress</div>
    </div>
</div>

<div class="shell">
    <div class="card">
        <div style="border-bottom:1px solid var(--line); padding-bottom:16px; margin-bottom:20px; display:flex; flex-direction:column; gap:12px;">
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <span style="font-size:14px; font-weight:500; color:var(--ink);">Membership fee</span>
                @if ($feePaid)
                    <span class="pill pill-approved">Paid</span>
                @else
                    <span class="pill pill-pending">Not paid</span>
                @endif
            </div>
            {{-- The renewal date is the fact a partner actually needs from this
                 page; "paid" only says they joined at some point. --}}
            @if ($feePaid && ! $profile->is_system_native)
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                <span style="font-size:14px; font-weight:500; color:var(--ink);">Membership</span>
                @if ($profile->membershipLapsed())
                    <span class="pill pill-rejected">
                        Ended {{ $profile->membership_expires_at->format('j M Y') }}
                    </span>
                @elseif ($profile->membership_expires_at)
                    <span class="pill {{ $profile->membershipEndingSoon() ? 'pill-pending' : 'pill-approved' }}">
                        Until {{ $profile->membership_expires_at->format('j M Y') }}
                    </span>
                @else
                    <span class="pill pill-pending">Not set</span>
                @endif
            </div>
            @endif
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <span style="font-size:14px; font-weight:500; color:var(--ink);">Review status</span>
                <span class="pill pill-{{ $status }}">{{ ucfirst($status) }}</span>
            </div>
        </div>

        @if (! $feePaid)
            <a class="btn btn-block" href="{{ route('notary.onboarding.fee') }}" style="justify-content:center;">
                <x-heroicon-o-credit-card style="width:16px;height:16px;"/>
                Pay membership fee
            </a>
        @elseif ($profile->membershipEndingSoon())
            {{-- Ahead of the review status: a lapsed membership is the thing
                 stopping them being booked, whatever the review says. --}}
            <a class="btn btn-block" href="{{ route('notary.onboarding.fee') }}" style="justify-content:center;">
                <x-heroicon-o-arrow-path style="width:16px;height:16px;"/>
                {{ $profile->membershipLapsed() ? 'Renew to be listed again' : 'Renew for another year' }}
            </a>
        @elseif ($status === 'pending')
            <div class="alert" style="background:var(--warning-bg); border:1px solid #e8c97a; color:var(--warning); display:flex; align-items:flex-start; gap:10px;">
                <x-heroicon-o-clock style="width:16px;height:16px;flex-shrink:0;margin-top:2px;"/>
                <span>Your credentials are with our team for review. You'll get an email once a decision is made — usually within 48 hours.</span>
            </div>
        @elseif ($status === 'approved')
            <div class="alert" style="background:var(--success-bg); border:1px solid var(--success-line); color:var(--success); display:flex; align-items:flex-start; gap:10px; margin-bottom:16px;">
                <x-heroicon-o-check-circle style="width:16px;height:16px;flex-shrink:0;margin-top:2px;"/>
                <span>Your application has been approved! Complete your profile and send it to us —
                    we check every notary's signature, stamp and seal by hand before listing them.</span>
            </div>
            <a class="btn btn-block" href="{{ route('notary.profile.edit') }}" style="justify-content:center;">
                Complete your profile &rarr;
            </a>
        @elseif ($status === 'rejected')
            <div class="alert alert-error" style="display:flex; align-items:flex-start; gap:10px;">
                <x-heroicon-o-x-circle style="width:16px;height:16px;flex-shrink:0;margin-top:2px;"/>
                <span>{{ $profile->review_notes ?: 'Your application was not approved. Please contact support for more information.' }}</span>
            </div>
        @endif
    </div>
</div>
@endsection
