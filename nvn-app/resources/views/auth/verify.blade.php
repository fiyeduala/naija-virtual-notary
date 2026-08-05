@extends('layouts.auth', ['title' => 'Verify your account', 'subtitle' => 'Account verification'])

@section('content')
<p style="font-size:14px;color:var(--muted);margin:0 0 4px;">
    We sent a 6-digit code to
    <strong style="color:var(--ink);">{{ $email }}</strong>.
    Enter it below to verify your account.
</p>

<form method="POST" action="{{ route('verify.submit') }}">
    @csrf
    <label for="code">Verification code</label>
    <input id="code" class="otp-input" type="text" name="code" inputmode="numeric"
           maxlength="6" pattern="[0-9]{6}" required autofocus autocomplete="one-time-code">
    <button type="submit">Verify account</button>
</form>

<form method="POST" action="{{ route('verify.resend') }}" style="text-align:center;">
    @csrf
    <button type="submit" class="muted-link">Didn't get it? Resend code</button>
</form>

<form method="POST" action="{{ route('logout') }}" style="text-align:center;">
    @csrf
    <button type="submit" class="muted-link" style="color:var(--muted);">Sign out</button>
</form>
@endsection
