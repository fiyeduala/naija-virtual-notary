@extends('layouts.app', ['title' => $request->reference])

@php
    $isDraft  = $request->status === \App\Enums\RequestStatus::Draft;
    $finals   = $request->finalDocuments;
    $docs     = $request->notarizableDocuments;
    // What this job is worth, said honestly in both worlds: on a notary's job
    // that is the platform's fee they paid, on an admin's job it is what the
    // client handed over at the desk, which no fee table knows about.
    $paidMinor = $request->amountPaidMinor();
    $settled   = $paidMinor > 0
        ? \App\Models\NotarizationRequest::money($paidMinor, $request->currency ?: 'NGN')
        : $request->displayFee();
@endphp

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('notary.offsite.index') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to offsite jobs
        </a>
        <h1>{{ $request->reference }}</h1>
        <div class="sub">{{ $request->document_use ?: 'Offsite notarization' }}</div>
    </div>
</div>

<div class="shell" style="max-width:820px;">

    {{-- What to do next, said once, at the top. Everything below is detail. --}}
    <div class="card" style="margin-bottom:16px;">
        @if ($finals->isNotEmpty())
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                <span class="pill pill-approved">Sealed</span>
                <span class="text-sm muted">Finished {{ $request->completed_at?->diffForHumans() }}</span>
            </div>
            <p class="text-sm" style="margin-bottom:14px;">
                @if ($isAdmin)
                    {{ $request->notary?->user?->full_name ?? 'The notary' }}&rsquo;s marks are on
                @else
                    Your marks are on
                @endif
                {{ $finals->count() === 1 ? 'the document' : 'all ' . $finals->count() . ' documents' }}.
                Download {{ $finals->count() === 1 ? 'it' : 'them' }} as many times as you need —
                {{ $finals->count() === 1 ? 'this file stays' : 'these files stay' }} here.
            </p>
            @foreach ($finals as $final)
            <a class="btn btn-sm" style="margin:0 8px 8px 0;"
               href="{{ route('client.documents.download', [$request, 'document' => $final->id]) }}">
                <x-heroicon-o-arrow-down-tray style="width:15px;height:15px;"/>
                {{ $final->original_filename ?: 'Sealed document ' . $loop->iteration }}
            </a>
            @endforeach

        @elseif ($isDraft && $isAdmin)
            {{-- The admin's own version of the payment step. No checkout: the
                 client paid the desk directly, so the only thing left to do is
                 write down what arrived. The figure is theirs, not the fee
                 table's — this is real revenue and it belongs on the books at
                 its true size. --}}
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                <span class="pill pill-pending">Not opened yet</span>
                <span class="text-sm muted">
                    {{ $docs->count() }} {{ \Illuminate\Support\Str::plural('document', $docs->count()) }}
                    · sealed by {{ $request->notary?->user?->full_name ?? 'unassigned' }}
                </span>
            </div>

            @if ($blocked)
                <div class="alert alert-error" style="display:flex; gap:10px; align-items:flex-start;">
                    <x-heroicon-o-exclamation-triangle style="width:18px;height:18px;flex:none;margin-top:1px;"/>
                    <div>{{ $blocked }}</div>
                </div>
            @else
                <p class="text-sm" style="margin-bottom:16px;">
                    Record what was paid, and the job opens for sealing. It goes on the books as
                    platform revenue — none of it is owed to a notary, so nothing here reaches a
                    payout.
                </p>

                <form method="POST" action="{{ route('notary.offsite.record', $request) }}">
                    @csrf

                    <label for="amount_major" style="margin-bottom:8px;">How much was paid?</label>
                    <input id="amount_major" type="number" name="amount_major" step="0.01" min="0" required
                           value="{{ old('amount_major') }}" placeholder="0.00" style="max-width:220px;">
                    <div class="text-sm muted" style="margin-top:6px;">
                        In {{ $request->currency ?: 'NGN' }}, and the real figure. For a client who
                        paid the desk directly that is what they handed over for the notarization,
                        not the platform's {{ $unitFee }} per-document fee. For a partner settling
                        their own sealing fee by transfer, it is that fee. Enter 0 to open the job
                        without recording money.
                    </div>

                    <label for="method" style="margin-bottom:8px; margin-top:20px;">How did it arrive?</label>
                    <select id="method" name="method" required style="max-width:320px;">
                        @foreach ($methods as $value => $label)
                        <option value="{{ $value }}" @selected(old('method', 'bank_transfer') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <label for="received_at" style="margin-bottom:8px; margin-top:20px;">When did it arrive?</label>
                    <input id="received_at" type="datetime-local" name="received_at" style="max-width:260px;"
                           max="{{ now()->format('Y-m-d\TH:i') }}"
                           value="{{ old('received_at', now()->format('Y-m-d\TH:i')) }}">
                    <div class="text-sm muted" style="margin-top:6px;">
                        Not necessarily today. This is the date the payment counts from.
                    </div>

                    <label for="reference" style="margin-bottom:8px; margin-top:20px;">Their reference</label>
                    <input id="reference" type="text" name="reference" maxlength="255"
                           value="{{ old('reference') }}"
                           placeholder="Transfer session ID, teller number, POS slip">
                    <div class="text-sm muted" style="margin-top:6px;">
                        Optional, but it is how you match this to the bank statement later.
                    </div>

                    <label for="note" style="margin-bottom:8px; margin-top:20px;">Note</label>
                    <textarea id="note" name="note" rows="2" maxlength="1000"
                              placeholder="Who the client was, which account it landed in, anything unusual">{{ old('note') }}</textarea>

                    <button type="submit" class="btn btn-lg" style="margin-top:20px;">
                        Record it and open the job
                    </button>
                </form>
            @endif

        @elseif ($isDraft)
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                <span class="pill pill-pending">Awaiting payment</span>
            </div>
            <p class="text-sm" style="margin-bottom:4px;">
                {{ $docs->count() }} {{ \Illuminate\Support\Str::plural('document', $docs->count()) }}
                &times; {{ $unitFee }}
            </p>
            <p style="font-size:22px; font-weight:700; color:var(--ink); margin-bottom:14px;">
                {{ $request->displayFee() }}
            </p>

            @if ($blocked)
                <div class="alert alert-error" style="display:flex; gap:10px; align-items:flex-start;">
                    <x-heroicon-o-exclamation-triangle style="width:18px;height:18px;flex:none;margin-top:1px;"/>
                    <div>{{ $blocked }}</div>
                </div>
            @else
                <form method="POST" action="{{ route('notary.offsite.pay', $request) }}">
                    @csrf
                    <button type="submit" class="btn btn-lg">
                        @if ($balance <= 0)
                            Unlock the editor — no charge
                        @else
                            Pay {{ $request->displayFee() }} and open the editor
                        @endif
                    </button>
                </form>
                <div class="text-sm muted" style="margin-top:10px;">
                    You keep whatever you charged your own client. This is the platform's fee for
                    sealing, and nothing else is deducted.
                </div>
            @endif

        @else
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                <span class="pill pill-approved">{{ $isAdmin ? 'Open' : 'Paid' }}</span>
                <span class="text-sm muted">{{ $settled }} · {{ $request->paid_at?->diffForHumans() }}</span>
            </div>
            <p class="text-sm" style="margin-bottom:14px;">
                @if ($isAdmin)
                    Place {{ $request->notary?->user?->full_name ?? 'the notary' }}&rsquo;s signature, stamp and
                    seal on
                @else
                    Place your signature, stamp and seal on
                @endif
                {{ $docs->count() === 1 ? 'the document' : 'each of the ' . $docs->count() . ' documents' }},
                then finalize. Nothing is charged again.
            </p>
            <a href="{{ route('session.notarize', $request) }}" class="btn btn-lg">
                <x-heroicon-o-pencil-square style="width:16px;height:16px;"/>
                Notarize now
            </a>
        @endif
    </div>

    {{-- The documents themselves --}}
    <div class="card">
        <h2 style="font-size:15px; margin-bottom:12px;">
            {{ $isDraft ? 'Documents on this job' : 'What was notarized' }}
        </h2>

        @foreach ($docs as $doc)
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;
                    padding:10px 0; border-bottom:1px solid var(--line);">
            <a class="text-sm" style="color:var(--ink); min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
               href="{{ route('notary.offsite.document', [$request, $doc]) }}" target="_blank" rel="noopener">
                {{ $doc->original_filename ?: 'Document ' . $loop->iteration }}
            </a>
            @if ($isDraft && $docs->count() > 1)
            <form method="POST" action="{{ route('notary.offsite.documents.remove', [$request, $doc]) }}"
                  onsubmit="return confirm('Remove this document from the job?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
            </form>
            @endif
        </div>
        @endforeach

        @if ($isDraft)
        {{-- Only before payment. The fee is per document and the total was
             agreed at checkout, so a document added afterwards would either be
             sealed for nothing or quietly reopen a balance on a job that reads
             as settled. --}}
        <form method="POST" action="{{ route('notary.offsite.documents.add', $request) }}"
              enctype="multipart/form-data" style="margin-top:16px;">
            @csrf
            <label for="more" style="margin-bottom:8px;">Add another document</label>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input id="more" type="file" name="documents[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                       multiple required class="text-sm">
                <button type="submit" class="btn btn-ghost btn-sm">Add</button>
            </div>
            <div class="text-sm muted" style="margin-top:6px;">
                Each one adds {{ $unitFee }} to the total. After you pay, the job is fixed —
                start a new one for anything else.
            </div>
        </form>
        @endif
    </div>

</div>
@endsection
