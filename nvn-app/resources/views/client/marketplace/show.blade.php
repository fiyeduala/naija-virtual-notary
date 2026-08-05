@extends('layouts.app', ['title' => 'Book notary'])

@push('styles')
<style>:root { --page-w: 780px; }</style>
@endpush

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('client.marketplace.index', $request) }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            All notaries
        </a>
        @php $specialties = collect($notary->specialties)->filter(); @endphp
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <h1>{{ $notary->user->full_name }}</h1>
            @if ($notary->is_system_native)
                <span class="pill" style="background:rgba(255,255,255,.14); color:#fff;">Platform notary</span>
            @endif
        </div>
        <div class="sub">
            @if ($specialties->isNotEmpty())
                {{ $specialties->map(fn($s) => \App\Enums\Specialty::from($s)->label())->implode(', ') }}
            @elseif ($notary->is_system_native)
                Notarized in-house by the Naija Virtual Notary desk
            @endif
        </div>
    </div>
</div>

<div class="shell">

    @if ($notary->bio)
    <div class="card" style="margin-bottom:16px;">
        <p style="font-size:14px; color:var(--ink); line-height:1.7;">{{ $notary->bio }}</p>
    </div>
    @endif

    <form method="POST" action="{{ route('client.marketplace.select', $request) }}" class="card">
        @csrf
        <input type="hidden" name="notary_id" value="{{ $notary->id }}">

        {{-- Service selection --}}
        <h2 style="margin-bottom:16px;">Choose a service</h2>
        @foreach ($notary->services as $service)
            <label style="display:flex; gap:12px; align-items:flex-start; padding:12px 0; border-bottom:1px solid var(--line); cursor:pointer;">
                <input type="radio" name="service_id" value="{{ $service->id }}"
                    style="width:auto; margin-top:3px; accent-color:var(--brand);" required>
                <span style="flex:1;">
                    <span style="display:block; font-size:14px; font-weight:600; color:var(--ink); margin-bottom:2px;">{{ $service->service_type }}</span>
                    @if ($service->description)
                        <span class="text-sm muted">{{ $service->description }}</span>
                    @endif
                    @if ($service->estimated_duration_minutes)
                        <span class="text-sm muted" style="display:flex; align-items:center; gap:4px; margin-top:3px;">
                            <x-heroicon-o-clock style="width:12px;height:12px;"/>
                            {{ $service->estimated_duration_minutes }} min
                        </span>
                    @endif
                </span>
                <span class="pill" style="flex-shrink:0;">{{ $service->displayPrice($request->currency) }}</span>
            </label>
        @endforeach

        {{-- Time — optional. Default is "as soon as possible"; the two compact
             dropdowns only appear if the client actually wants a fixed slot. --}}
        @php
            $slotDays = collect($slots)->filter(fn ($daySlots) => count($daySlots))->all();
            $hasSlots = count($slotDays) > 0;

            // Built here rather than inline in @json(...) — Blade's directive
            // parser mis-reads a multi-line nested call and truncates it.
            $slotsJson = collect($slotDays)
                ->map(fn ($daySlots) => collect($daySlots)->map(fn ($s) => ['start' => $s['start'], 'label' => $s['label']])->all())
                ->toJson();
        @endphp

        <h2 style="margin-top:28px; margin-bottom:4px;">When?</h2>
        <p class="text-sm muted" style="margin-bottom:14px;">Optional — you can leave this and the notary will reach out to agree a time.</p>

        <div class="when-choice">
            <label class="when-opt">
                <input type="radio" name="when" value="asap" checked style="width:auto; accent-color:var(--brand);">
                <span>
                    <strong>As soon as possible</strong>
                    <span class="text-sm muted" style="display:block;">Recommended — no time to pick</span>
                </span>
            </label>
            <label class="when-opt">
                <input type="radio" name="when" value="fixed" style="width:auto; accent-color:var(--brand);" @disabled(! $hasSlots)>
                <span>
                    <strong>Pick a specific time</strong>
                    <span class="text-sm muted" style="display:block;">
                        {{ $hasSlots ? 'Choose a day and a slot' : 'No open slots in the next two weeks' }}
                    </span>
                </span>
            </label>
        </div>

        @if ($hasSlots)
        <div id="slot-picker" class="grid-2" style="margin-top:14px; display:none;">
            <div>
                <label for="slot-day" style="margin-top:0;">Day</label>
                <select id="slot-day">
                    @foreach ($slotDays as $date => $daySlots)
                        <option value="{{ $date }}">{{ \Carbon\Carbon::parse($date)->format('D, j M') }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="slot_start" style="margin-top:0;">Time</label>
                <select id="slot_start" name="slot_start" disabled></select>
            </div>
        </div>
        @endif

        <button class="btn btn-block" type="submit" style="margin-top:22px; justify-content:center;">
            Continue to review &rarr;
        </button>
    </form>

</div>
@endsection

@push('styles')
<style>
    .when-choice { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .when-opt {
        display: flex; gap: 10px; align-items: flex-start; margin: 0;
        padding: 12px 14px; border: 1.5px solid var(--line);
        border-radius: var(--radius-sm); cursor: pointer;
        font-size: 14px; font-weight: 400; transition: border-color .15s, background .15s;
    }
    .when-opt:has(input:checked) { border-color: var(--brand); background: var(--brand-light); }
    .when-opt:has(input:disabled) { opacity: .55; cursor: not-allowed; }
    .when-opt strong { font-size: 14px; font-weight: 600; }
    @media (max-width: 580px) { .when-choice { grid-template-columns: 1fr; } }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const picker = document.getElementById('slot-picker');
    if (!picker) return;

    const daySelect  = document.getElementById('slot-day');
    const timeSelect = document.getElementById('slot_start');
    const slotsByDay = {!! $slotsJson !!};

    function fillTimes() {
        timeSelect.innerHTML = '';
        (slotsByDay[daySelect.value] || []).forEach(function (slot) {
            const opt = document.createElement('option');
            opt.value = slot.start;
            opt.textContent = slot.label;
            timeSelect.appendChild(opt);
        });
    }

    // A disabled select is not submitted at all — that is how "as soon as
    // possible" reaches the server with no slot_start key.
    function sync() {
        const fixed = document.querySelector('input[name="when"]:checked').value === 'fixed';
        picker.style.display = fixed ? '' : 'none';
        timeSelect.disabled = !fixed;
        if (fixed && !timeSelect.options.length) fillTimes();
    }

    daySelect.addEventListener('change', fillTimes);
    document.querySelectorAll('input[name="when"]').forEach(r => r.addEventListener('change', sync));
    sync();
})();
</script>
@endpush
