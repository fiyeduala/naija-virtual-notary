@extends('layouts.app', ['title' => 'Choose the right category'])

@push('styles')
<style>
    :root { --page-w: 680px; }

    /* One row per category. A radio card rather than a <select>, because the
       decision here is a comparison of prices against what has already been
       paid, and a dropdown hides every option but one at the moment the client
       most needs to see them side by side. */
    .cat-list { display: flex; flex-direction: column; gap: 10px; }
    .cat {
        display: flex; align-items: flex-start; gap: 12px;
        padding: 14px 16px; border: 1px solid var(--line);
        border-radius: var(--radius-sm); cursor: pointer; background: var(--surface);
        transition: border-color .12s ease, background .12s ease;
    }
    .cat:hover { border-color: var(--brand); }
    .cat input { margin-top: 3px; flex-shrink: 0; }
    .cat:has(input:checked) { border-color: var(--brand); background: var(--brand-light); }
    /* Spans, not divs — the whole row is a <label>, and a <div> inside one is
       legal but reads badly to a screen reader walking the label's text. Made
       block-level here so the margins below actually apply. */
    .cat-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
    .cat-name { font-weight: 600; font-size: 14px; }
    .cat-note { font-size: 12.5px; color: var(--muted); line-height: 1.5; }
    .cat-price { font-weight: 700; font-size: 14px; color: var(--brand-dark); white-space: nowrap; }
    .cat-tag {
        display: inline-block; margin-left: 8px; padding: 2px 8px; border-radius: 999px;
        font-size: 11px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase;
        background: var(--warning-bg); color: var(--warning);
    }
</style>
@endpush

@section('content')
@php
    $currency = $request->currency ?: 'NGN';
    $paid     = \App\Models\NotarizationRequest::money($request->amountPaidMinor(), $currency);
    $count    = $request->billableDocumentCount();
@endphp

<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('client.dashboard') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to my requests
        </a>
        <h1>Choose the right category</h1>
        <div class="sub">{{ $request->reference }}</div>
    </div>
</div>

<div class="shell">

    {{-- The reassurance comes first and on its own. A client who has paid and
         is then told something is wrong assumes the money is gone; every other
         thing on this page is unreadable until that fear is answered. --}}
    <div class="bank-state is-ok" style="margin-bottom:16px;">
        <strong>Your payment is safe.</strong>
        <span>
            The {{ $paid }} you have already paid stays on this request. Nothing has been refunded,
            nothing has been cancelled, and you will never be asked to pay it again.
        </span>
    </div>

    {{-- What the desk said --}}
    <div class="card">
        <h2 style="margin-bottom:6px;">What we found</h2>
        <p class="text-sm muted" style="margin-bottom:14px;">
            Raised by {{ $request->categoryQueriedBy?->full_name ?? 'your notary' }}
            {{ $request->category_query_at?->diffForHumans() }}.
        </p>
        <p style="font-size:14.5px; line-height:1.6; margin:0 0 16px;">{{ $request->category_query_reason }}</p>

        <div style="display:flex; justify-content:space-between; padding:9px 0; border-top:1px solid var(--line);">
            <span class="text-sm muted">Booked as</span>
            <span class="text-sm" style="font-weight:500;">
                {{ $request->service?->service_type ?? '—' }}
                @if ($request->service)
                    <span class="muted" style="font-weight:400;">— {{ $request->service->displayPrice($currency) }}</span>
                @endif
            </span>
        </div>
        <div style="display:flex; justify-content:space-between; padding:9px 0; border-top:1px solid var(--line);">
            <span class="text-sm muted">Paid so far</span>
            <span class="text-sm" style="font-weight:600; color:var(--success);">{{ $paid }}</span>
        </div>
        @if ($count > 1)
        <div style="display:flex; justify-content:space-between; padding:9px 0; border-top:1px solid var(--line);">
            <span class="text-sm muted">Documents</span>
            <span class="text-sm" style="font-weight:500;">{{ $count }} — each one is priced separately</span>
        </div>
        @endif
    </div>

    @if ($request->hasOpenCategoryQuery())
        {{-- Still to answer --}}
        <div class="card" style="margin-top:16px;">
            <h2 style="margin-bottom:6px;">Pick the category that fits</h2>
            <p class="text-sm muted" style="margin-bottom:18px;">
                These are {{ $request->notary?->user?->full_name ?? 'your notary' }}&rsquo;s prices.
                {{-- Written out rather than an inline @if: Blade will not compile a
                     directive glued to the end of a word ("document@if"), and leaves
                     it as literal text while still eating the matching @endif. --}}
                {{ $count > 1
                    ? 'Prices shown are per document, so the total below is for all ' . $count . '.'
                    : 'Prices shown are per document.' }}
                If you are not sure which one applies,
                <a href="{{ route('messages.show', $request) }}">ask them</a> before choosing.
            </p>

            <form method="POST" action="{{ route('client.request.category.update', $request) }}">
                @csrf
                <div class="cat-list">
                    @foreach ($services as $service)
                        @php
                            // What this choice would cost in total, and what is
                            // left after the money already on the request. The
                            // difference is the only number most clients read.
                            $wouldCost = $service->priceFor($currency) * $count;
                            $diff      = $wouldCost - $request->amountPaidMinor();
                            $isCurrent = $service->id === $request->service_id;
                            $isPicked  = $service->id === $request->category_suggested_service_id;
                        @endphp
                        <label class="cat">
                            <input type="radio" name="service_id" value="{{ $service->id }}"
                                   {{ $isPicked ? 'checked' : '' }} required>
                            <span class="cat-body">
                                <span class="cat-name">
                                    {{ $service->service_type }}
                                    @if ($isPicked)<span class="cat-tag">We think this one</span>@endif
                                    @if ($isCurrent)<span class="cat-tag">What you booked</span>@endif
                                </span>
                                @if ($service->description)
                                    <span class="cat-note">{{ $service->description }}</span>
                                @endif
                                <span class="cat-note">
                                    @if ($diff > 0)
                                        You would pay <strong>{{ \App\Models\NotarizationRequest::money($diff, $currency) }}</strong> more.
                                    @elseif ($diff < 0)
                                        {{ \App\Models\NotarizationRequest::money(abs($diff), $currency) }} less than you have paid —
                                        we will contact you about the difference.
                                    @else
                                        Nothing more to pay.
                                    @endif
                                </span>
                            </span>
                            <span class="cat-price">
                                {{ $service->displayPrice($currency) }}@if ($count > 1)<span class="cat-note" style="display:block; text-align:right;">&times; {{ $count }}</span>@endif
                            </span>
                        </label>
                    @endforeach
                </div>

                @if ($services->isEmpty())
                    <p class="text-sm muted">
                        Your notary has no other categories priced right now.
                        <a href="{{ route('messages.show', $request) }}">Message them</a> and they will sort it out with you.
                    </p>
                @else
                    <button class="btn btn-block btn-lg" type="submit" style="justify-content:center; margin-top:20px;">
                        Confirm this category
                    </button>
                    <p class="text-sm muted" style="text-align:center; margin-top:10px;">
                        You will see exactly what is left to pay before any payment is taken.
                    </p>
                @endif
            </form>
        </div>
    @else
        {{-- Answered, and it cost more --}}
        <div class="card" style="margin-top:16px;">
            <h2 style="margin-bottom:6px;">One last step</h2>
            <p class="text-sm muted" style="margin-bottom:18px;">
                {{ $request->reference }} is now filed as <strong>{{ $request->service?->service_type }}</strong>.
                That is all your notary needs — except the difference in price.
            </p>

            <div style="display:flex; justify-content:space-between; padding:9px 0; border-bottom:1px solid var(--line);">
                <span class="text-sm muted">{{ $request->service?->service_type }}@if ($count > 1) &times; {{ $count }}@endif</span>
                <span class="text-sm" style="font-weight:500;">{{ $request->displayFee() }}</span>
            </div>
            <div style="display:flex; justify-content:space-between; padding:9px 0; border-bottom:1px solid var(--line);">
                <span class="text-sm muted">Already paid</span>
                <span class="text-sm" style="font-weight:500; color:var(--success);">&minus; {{ $paid }}</span>
            </div>
            <div style="display:flex; justify-content:space-between; padding:14px 0 2px;">
                <span style="font-weight:700; font-size:15px;">To pay now</span>
                <span style="font-weight:800; font-size:18px; color:var(--brand-dark);">{{ $request->displayBalance() }}</span>
            </div>

            <form method="POST" action="{{ route('client.request.payment.pay', $request) }}" style="margin-top:20px;">
                @csrf
                <button class="btn btn-block btn-lg" type="submit" style="justify-content:center;">
                    <x-heroicon-o-credit-card style="width:18px;height:18px;"/>
                    Pay {{ $request->displayBalance() }} with Paystack
                </button>
            </form>
            <p class="text-sm muted" style="text-align:center; margin-top:10px;">
                Only the difference. Your notary picks the job back up as soon as this clears.
            </p>
        </div>
    @endif

</div>
@endsection
