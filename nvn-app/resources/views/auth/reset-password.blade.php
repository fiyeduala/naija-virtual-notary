@extends('layouts.auth', ['title' => 'Choose a new password', 'subtitle' => 'Choose a new password'])

@section('content')
<form method="POST" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">

    <label for="email">Email address</label>
    <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required readonly
           style="background:#f6f7f8; color:var(--muted);">

    <label for="password">New password</label>
    <input id="password" type="password" name="password" required autofocus>
    <p style="font-size:12px; color:var(--muted); margin:6px 0 0;">
        At least 8 characters, with upper and lower case letters and a number.
    </p>

    <label for="password_confirmation">Confirm new password</label>
    <input id="password_confirmation" type="password" name="password_confirmation" required>

    <button type="submit">Reset password</button>
</form>

<div class="alt">
    <a href="{{ route('login') }}">Back to sign in</a>
</div>
@endsection
