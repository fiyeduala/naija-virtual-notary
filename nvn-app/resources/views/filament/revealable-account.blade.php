{{--
    A payout account number that stays hidden until somebody asks for it.

    Masking used to be absolute here: the full number never reached the browser
    at all. That was the right default and the wrong rule, because the platform
    pays most partners by hand — an admin sitting in their bank app needs the
    ten digits, and "open the database" is not a workflow.

    So the number is now in the page but not on it. The masking still does its
    real job, which is the ordinary one: nobody screen-shares, screenshots or
    walks past a list of partner account numbers by accident. Revealing is a
    deliberate click, and the copy button means the digits usually go straight
    to the clipboard without ever being read aloud.
--}}
@php
    // Two callers with two shapes: a form Placeholder hands the bank row in
    // directly, an infolist ViewEntry hands in the notary and a $getRecord
    // closure. Resolving both here keeps one partial instead of two.
    $bank ??= isset($getRecord) ? ($getRecord()?->bankDetails ?? null) : null;

    $number = (string) ($bank?->account_number ?? '');
    $masked = $bank?->maskedAccountNumber() ?? '—';
@endphp

@once
<style>
    .nvn-acct { display:inline-flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .nvn-acct__num {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 13px; letter-spacing: .08em; font-weight: 600;
    }
    .nvn-acct__btn {
        border: 1px solid #d1d5db; background: #fff; color: #374151;
        border-radius: 6px; padding: 2px 8px; font-size: 11px; font-weight: 600;
        cursor: pointer; line-height: 1.7;
    }
    .nvn-acct__btn:hover { background: #f3f4f6; }
    .dark .nvn-acct__btn { border-color: #4b5563; background: transparent; color: #d1d5db; }
    .dark .nvn-acct__btn:hover { background: #374151; }
</style>
@endonce

@if ($number === '')
    <span class="nvn-acct__num">—</span>
@else
    <span class="nvn-acct" x-data="{ shown: false, copied: false }">
        {{-- Two spans rather than one with a swapped string: the masked form is
             what the page renders without JavaScript, and it stays the thing
             that is there when nothing has been clicked. --}}
        <span class="nvn-acct__num" x-show="! shown">{{ $masked }}</span>
        <span class="nvn-acct__num" x-show="shown" style="display:none;">{{ $number }}</span>

        <button type="button" class="nvn-acct__btn"
                x-on:click="shown = ! shown"
                x-text="shown ? 'Hide' : 'Show'">Show</button>

        <button type="button" class="nvn-acct__btn"
                x-on:click="navigator.clipboard.writeText('{{ $number }}'); copied = true; setTimeout(() => copied = false, 1500)"
                x-text="copied ? 'Copied' : 'Copy'">Copy</button>
    </span>
@endif
