@extends('layouts.auth', ['title' => 'Reset portal password', 'subtitle' => 'Partner portal'])

@section('content')
<p style="font-size:13.5px; line-height:1.6; color:var(--muted); margin:0 0 4px;">
    Give us the address your portal signs in with and we will send a link to
    choose a new password.
</p>

<form method="POST" action="{{ route('organization.password.email') }}">
    @csrf

    <label for="email">Organization email</label>
    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

    <button type="submit">Send reset link</button>
</form>

<div class="alt">
    <a href="{{ route('organization.login') }}">Back to sign in</a>
</div>
@endsection
