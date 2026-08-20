{{--
    The whole destination of a manual transfer, on one line: bank, number,
    who the bank says owns it, and whether that was ever checked.

    Kept as its own partial rather than inlined into the settlement form so the
    unverified warning cannot drift apart from the number it is warning about.
--}}
<span style="display:inline-flex; align-items:center; gap:8px; flex-wrap:wrap;">
    <span style="font-weight:600;">{{ $bank->bank_name ?: 'Bank not named' }}</span>

    @include('filament.revealable-account', ['bank' => $bank])

    <span>{{ $bank->resolved_account_name ?: $bank->account_name }}</span>

    @if ($bank->isVerified())
        <span style="font-size:11px; color:#15803d; font-weight:600;">verified with the bank</span>
    @else
        <span style="font-size:11px; color:#b45309; font-weight:600;">not verified — check it first</span>
    @endif
</span>
