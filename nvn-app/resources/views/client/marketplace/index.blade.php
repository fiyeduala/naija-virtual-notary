@extends('layouts.app', ['title' => 'Choose a notary'])

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('client.dashboard') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to dashboard
        </a>
        <h1>Choose a notary</h1>
        <div class="sub">Browse approved notaries and select one to continue your request</div>
    </div>
</div>

<div class="shell">

    {{-- Search / filter --}}
    <form method="GET" action="{{ route('client.marketplace.index', $request) }}" class="card" style="margin-bottom:20px;">
        <div class="grid-2">
            <div>
                <label for="q">Search by name</label>
                <input id="q" type="text" name="q" value="{{ $q }}" placeholder="Notary name">
            </div>
            <div>
                <label for="specialty">Filter by specialty</label>
                <select id="specialty" name="specialty">
                    <option value="">All specialties</option>
                    @foreach (\App\Enums\Specialty::cases() as $s)
                        <option value="{{ $s->value }}" @selected($specialty===$s->value)>{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div style="display:flex; gap:10px; margin-top:14px;">
            <button class="btn btn-ghost btn-sm" type="submit">Apply filter</button>
            @if($q || $specialty)
                <a href="{{ route('client.marketplace.index', $request) }}" class="btn btn-ghost btn-sm">Clear</a>
            @endif
        </div>
    </form>

    {{-- Notary list --}}
    @forelse ($notaries as $notary)
    <div class="card" style="margin-bottom:12px; display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap;">
        <div style="flex:1; min-width:0;">
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:5px;">
                <span style="font-weight:700; font-size:15px; color:var(--ink);">{{ $notary->user->full_name }}</span>
                @if ($notary->is_system_native)
                    <span class="pill" style="background:var(--brand-light); color:var(--brand-dark);">Platform notary</span>
                @endif
            </div>
            @php $specialties = collect($notary->specialties)->filter(); @endphp
            <div class="text-sm muted" style="margin-bottom:6px;">
                @if ($specialties->isNotEmpty())
                    {{ $specialties->map(fn($s) => \App\Enums\Specialty::from($s)->label())->implode(', ') }}
                @elseif ($notary->is_system_native)
                    Handled in-house by the Naija Virtual Notary desk
                @endif
            </div>
            @php
                // "From" has to mean the cheapest service, not whichever row the
                // database happened to return first.
                $cheapest = $notary->services->sortBy(fn ($s) => $s->priceFor($request->currency))->first();
            @endphp
            @if ($cheapest)
                <div class="text-sm" style="color:var(--brand-dark); font-weight:500;">
                    From {{ $cheapest->displayPrice($request->currency) }}
                </div>
            @endif
        </div>
        <a class="btn btn-sm" href="{{ route('client.marketplace.show', [$request, $notary]) }}" style="flex-shrink:0;">
            View &amp; book &rarr;
        </a>
    </div>
    @empty
    <div style="background:var(--surface); border:2px dashed var(--line); border-radius:var(--radius-lg); padding:56px 24px; text-align:center; color:var(--muted);">
        <div style="color:var(--brand); opacity:.35; margin-bottom:14px;">
            <x-heroicon-o-user-group style="width:48px;height:48px;"/>
        </div>
        <p style="font-weight:600; color:var(--ink); margin-bottom:4px; font-size:15px;">No notaries match your filter</p>
        <small>Try clearing the search or removing the specialty filter.</small>
    </div>
    @endforelse

</div>
@endsection
