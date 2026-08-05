@extends('layouts.auth', ['title' => 'Sign in', 'subtitle' => 'Sign in to your account'])

@section('content')
<form method="POST" action="{{ route('login') }}">
    @csrf

    <label for="email">Email address</label>
    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

    <div style="display:flex; align-items:baseline; justify-content:space-between; gap:12px;">
        <label for="password">Password</label>
        <a href="{{ route('password.request') }}"
           style="font-size:12.5px; color:var(--brand); text-decoration:none; font-weight:600;">Forgot password?</a>
    </div>
    <input id="password" type="password" name="password" required>

    <div class="check">
        <input id="remember" type="checkbox" name="remember" value="1">
        <label for="remember" style="margin:0;font-weight:400;">Remember me on this device</label>
    </div>

    <button type="submit">Sign in</button>
</form>

<div class="alt">
    New here? <a href="{{ route('register') }}">Create an account</a>
</div>
@endsection
