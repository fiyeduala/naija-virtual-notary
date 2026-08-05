@extends('layouts.app', ['title' => 'Complete your profile'])

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('notary.dashboard') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to dashboard
        </a>
        <h1>Complete your profile</h1>
        <div class="sub">Add your notarial assets, bank details, and the services you offer — then go live in the marketplace</div>
    </div>
</div>

<div class="shell" style="padding-bottom:100px;">

    {{-- Assets --}}
    <form method="POST" action="{{ route('notary.profile.assets') }}" enctype="multipart/form-data" class="card">
        @csrf
        <h2 style="margin-bottom:18px;">E-signature &amp; identity</h2>
        <div class="grid-2">
            <div>
                <label for="initials">Initials (typed)</label>
                <input id="initials" type="text" name="initials" maxlength="10"
                    value="{{ optional($profile->assets->firstWhere('type','initials'))->text_value }}" required>
            </div>
            <div>
                <label for="scn">Supreme Court Number (SCN)</label>
                <input id="scn" type="text" name="scn" value="{{ $profile->scn }}" placeholder="e.g. SCN/1234/2019">
            </div>
        </div>
        <div class="grid-2" style="margin-top:4px;">
            <div>
                <label for="signature">Upload e-signature</label>
                <input id="signature" type="file" name="signature" accept=".png,.jpg,.jpeg" required>
            </div>
            <div>
                <label for="stamp">Upload official stamp</label>
                <input id="stamp" type="file" name="stamp" accept=".png,.jpg,.jpeg" required>
            </div>
        </div>
        <label for="seal">Upload official seal</label>
        <input id="seal" type="file" name="seal" accept=".png,.jpg,.jpeg" required>
        <button class="btn" type="submit" style="margin-top:20px;">Save assets</button>
    </form>

    {{-- Bank --}}
    @php $bank = $profile->bankDetails; @endphp
    <form method="POST" action="{{ route('notary.profile.bank') }}" class="card" style="margin-top:16px;">
        @csrf
        <h2 style="margin-bottom:6px;">Payout account</h2>
        <p class="muted text-sm" style="margin-bottom:18px;">
            This is where your earnings are paid. We check the account with your bank as soon as you save it.
        </p>

        {{-- What the bank itself said about the account already on file. --}}
        @if ($bank)
            <div class="bank-state {{ $bank->isVerified() ? ($bank->name_matches === false ? 'is-warn' : 'is-ok') : 'is-idle' }}">
                @if (! $bank->isVerified())
                    <strong>Not yet confirmed</strong>
                    <span>{{ $bank->bank_name }} · {{ $bank->maskedAccountNumber() }} — we could not reach your bank to check this. Save again to retry.</span>
                @elseif ($bank->name_matches === false)
                    <strong>Confirmed, but the name differs</strong>
                    <span>{{ $bank->bank_name }} · {{ $bank->maskedAccountNumber() }} — your bank holds this account as
                        “{{ $bank->resolved_account_name }}”. An admin will check it before your first payout.</span>
                @else
                    <strong>Confirmed by your bank</strong>
                    <span>{{ $bank->bank_name }} · {{ $bank->maskedAccountNumber() }} — “{{ $bank->resolved_account_name }}”. Ready for payouts.</span>
                @endif
            </div>
        @endif

        <div class="grid-2">
            <div>
                <label for="bank_code">Bank</label>
                <select id="bank_code" name="bank_code" required>
                    <option value="">Select your bank…</option>
                    @foreach (\App\Support\Banks::all() as $code => $name)
                        <option value="{{ $code }}" @selected(old('bank_code', $bank?->bank_code) === $code)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="account_number">Account number</label>
                <input id="account_number" type="text" name="account_number" inputmode="numeric"
                    maxlength="10" pattern="[0-9]{10}" placeholder="10 digits" required>
            </div>
        </div>
        <label for="account_name">Account name</label>
        <input id="account_name" type="text" name="account_name"
            value="{{ old('account_name', $bank?->account_name) }}" required>
        <p class="muted text-sm" style="margin-top:6px;">
            As it appears on your statement. We compare it with what your bank returns.
        </p>
        <button class="btn" type="submit" style="margin-top:20px;">Save payout account</button>
    </form>

    {{-- Services --}}
    <form method="POST" action="{{ route('notary.profile.service') }}" class="card" style="margin-top:16px;">
        @csrf
        <h2 style="margin-bottom:18px;">Add a service</h2>
        <label for="service_type">Service type</label>
        <select id="service_type" name="service_type" required>
            @foreach (\App\Enums\Specialty::cases() as $s)
                <option value="{{ $s->label() }}">{{ $s->label() }}</option>
            @endforeach
        </select>
        <div class="grid-2">
            <div>
                <label for="price_ngn">Price (₦ NGN)</label>
                <input id="price_ngn" type="number" step="0.01" min="0" name="price_ngn" required>
            </div>
            <div>
                <label for="price_usd">Price ($ USD)</label>
                <input id="price_usd" type="number" step="0.01" min="0" name="price_usd" required>
            </div>
        </div>
        <label for="duration">Estimated duration (minutes)</label>
        <input id="duration" type="number" name="duration" value="30" min="5" max="240" required>
        <label for="description">Description (optional)</label>
        <textarea id="description" name="description"></textarea>
        <button class="btn" type="submit" style="margin-top:20px;">Add service</button>
    </form>

    {{-- Services list --}}
    @if ($profile->services->count())
    <div class="card" style="margin-top:16px;">
        <h2 style="margin-bottom:16px;">Your services</h2>
        @foreach ($profile->services as $svc)
            <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--line);">
                <span style="font-size:14px; font-weight:500; color:var(--ink);">{{ $svc->service_type }}</span>
                <span class="text-sm muted">{{ $svc->displayPrice('NGN') }} &nbsp;/&nbsp; {{ $svc->displayPrice('USD') }}</span>
            </div>
        @endforeach
    </div>
    @endif

</div>

{{-- Sticky go-live bar --}}
<div class="sticky-bar">
    <form method="POST" action="{{ route('notary.profile.golive') }}">
        @csrf
        <button type="submit">Go live in the marketplace &rarr;</button>
    </form>
</div>
@endsection
