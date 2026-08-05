@extends('layouts.auth', ['title' => 'Forgot password', 'subtitle' => 'Reset your password'])

@section('content')
<p style="font-size:13px; color:var(--muted); line-height:1.6; margin:0 0 4px;">
    Enter the email address on your account and we'll send you a link to choose a new password.
</p>

<form method="POST" action="{{ route('password.email') }}">
    @csrf

    <label for="email">Email address</label>
    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

    <button type="submit">Send reset link</button>
</form>

<div class="alt">
    Remembered it? <a href="{{ route('login') }}">Back to sign in</a>
</div>
@endsection
