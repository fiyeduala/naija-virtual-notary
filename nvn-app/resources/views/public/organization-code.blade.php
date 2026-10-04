@extends('layouts.public', [
    'title' => 'Enter your organization code | Naija Virtual Notary',
    'description' => 'Were you sent here by a government body, an embassy, a law firm or your employer? Enter the code they gave you.',
])

{{--
    For a code that arrived on paper or down a telephone.

    A link is the ordinary way in, but a form at a counter and a line in a
    letter cannot be clicked. The code resolves to the same landing page, so
    there is one destination however somebody arrived at it.
--}}

@push('styles')
<style>
    .code-wrap { max-width: 520px; margin: 0 auto; text-align: center; }
    .code-card {
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--radius); padding: 34px 32px; margin-top: 30px; text-align: left;
    }
    .code-card label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 7px; }
    .code-card input {
        width: 100%; padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px;
        font-family: var(--font); font-size: 19px; font-weight: 600;
        letter-spacing: .14em; text-transform: uppercase; text-align: center;
    }
    .code-card input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(84,180,53,.12); }
    .code-card button {
        width: 100%; margin-top: 18px; padding: 14px; background: var(--brand); color: #fff;
        border: none; border-radius: 10px; font-family: var(--font); font-size: 16px;
        font-weight: 700; cursor: pointer;
    }
    .code-card button:hover { background: var(--brand-dark); }
    .code-errors {
        background: #fdecec; border: 1px solid #f5c2c2; color: #a12626;
        border-radius: 9px; padding: 12px 14px; font-size: 13px; margin-bottom: 16px;
    }
    .code-errors ul { margin: 0; padding-left: 18px; }
    .code-alt { font-size: 13.5px; color: var(--muted); margin-top: 22px; line-height: 1.7; }
    .code-alt a { color: var(--brand-dark); font-weight: 600; }
</style>
@endpush

@section('content')
<section class="section">
    <div class="code-wrap">
        <div class="section-label">Partner organizations</div>
        <h1 class="section-title">Enter your code</h1>
        <p class="section-sub" style="margin: 0 auto;">
            If a government body, an embassy, a law firm or your employer sent you
            here, type the code they gave you and we will apply their rates.
        </p>

        <div class="code-card">
            @if ($errors->any())
                <div class="code-errors">
                    <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('organization.redeem') }}">
                @csrf
                <label for="code">Organization code</label>
                <input id="code" name="code" value="{{ old('code') }}"
                       maxlength="60" autocomplete="off" autofocus required
                       placeholder="e.g. NIMCAB12">
                <button type="submit">Continue</button>
            </form>
        </div>

        <p class="code-alt">
            No code? You can
            <a href="{{ route('register') }}">notarize a document the ordinary way</a>.<br>
            Are you an organization wanting to send us work?
            <a href="{{ route('organization.apply.show') }}">Apply to partner with us</a>.
        </p>
    </div>
</section>
@endsection
