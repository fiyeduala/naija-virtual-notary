@extends('layouts.auth', ['title' => 'Choose a new password', 'subtitle' => 'Partner portal'])

@section('content')
<form method="POST" action="{{ route('organization.password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">

    <label for="email">Organization email</label>
    <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required>

    <label for="password">New password</label>
    <input id="password" type="password" name="password" required autofocus>

    <label for="password_confirmation">Confirm new password</label>
    <input id="password_confirmation" type="password" name="password_confirmation" required>

    <button type="submit">Save new password</button>
</form>

<div class="alt">
    <a href="{{ route('organization.login') }}">Back to sign in</a>
</div>
@endsection
