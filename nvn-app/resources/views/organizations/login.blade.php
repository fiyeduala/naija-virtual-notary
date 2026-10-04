@extends('layouts.auth', ['title' => 'Partner portal', 'subtitle' => 'Partner portal sign-in'])

@section('content')
{{-- The same layout as every other sign-in on the site, so a body's staff are
     not asked to learn a second-looking front door. The guard behind it is a
     different one; the screen need not say so. --}}
<form method="POST" action="{{ route('organization.login') }}">
    @csrf

    <label for="email">Organization email</label>
    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

    <div style="display:flex; align-items:baseline; justify-content:space-between; gap:12px;">
        <label for="password">Password</label>
        <a href="{{ route('organization.password.request') }}"
           style="font-size:12.5px; color:var(--brand); text-decoration:none; font-weight:600;">Forgot password?</a>
    </div>
    <input id="password" type="password" name="password" required>

    <div class="check">
        <input id="remember" type="checkbox" name="remember" value="1">
        <label for="remember" style="margin:0;font-weight:400;">Remember this device</label>
    </div>

    <button type="submit">Sign in</button>
</form>

<div class="aside">
    <h2>Not a partner yet?</h2>
    <p>Government bodies, embassies, law firms and companies can apply to send
       work to us on their own terms, at their own agreed rate.</p>
    <a class="aside-btn" href="{{ route('organization.apply.show') }}">Apply to partner with us</a>
</div>

<div class="alt" style="margin-top:14px;">
    Looking for your own documents? <a href="{{ route('login') }}">Sign in here</a>
</div>
@endsection
