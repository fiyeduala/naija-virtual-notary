@extends('layouts.auth', ['title' => 'Create account', 'subtitle' => 'Create your client account'])

@section('content')
<form method="POST" action="{{ route('register') }}">
    @csrf

    <label for="full_name">Full name</label>
    <input id="full_name" type="text" name="full_name" value="{{ old('full_name') }}" required autofocus>

    <label for="email">Email address</label>
    <input id="email" type="email" name="email" value="{{ old('email') }}" required>

    <label for="phone">Phone number</label>
    <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" required>

    <label for="password">Password</label>
    <input id="password" type="password" name="password" required>

    <label for="password_confirmation">Confirm password</label>
    <input id="password_confirmation" type="password" name="password_confirmation" required>

    <div class="check">
        <input id="terms" type="checkbox" name="terms" value="1" {{ old('terms') ? 'checked' : '' }}>
        <label for="terms" style="margin:0;font-weight:400;">
            I agree to the privacy policy and terms and conditions, and confirm this document will be used for legal means.
        </label>
    </div>

    <button type="submit">Create account</button>
</form>

<div class="alt">
    Already have an account? <a href="{{ route('login') }}">Sign in</a>
</div>
@endsection
