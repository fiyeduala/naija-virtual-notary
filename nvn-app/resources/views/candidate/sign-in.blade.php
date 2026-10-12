@extends('layouts.candidate', ['title' => 'Sign in to your application — Naija Virtual Notary'])

{{--
    Asking for a sign-in link.

    The message after submitting is the same whether or not the address is on
    file, which is the point: this form would otherwise answer "has this person
    applied to Naija Virtual Notary?" for anybody who typed an address into it.
--}}

@section('shell-class', 'narrow')

@section('content')

<h1>Your application</h1>
<p class="lede">
    There is no password to remember. Enter the email address you applied with and we will send
    you a link that signs you in.
</p>

<div class="card">
    <form method="POST" action="{{ route('candidate.login.request') }}">
        @csrf

        <div style="margin-bottom:18px;">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}"
                   required autocomplete="email" autofocus>
        </div>

        <button type="submit" class="btn">Email me a sign-in link</button>
    </form>
</div>

<p class="meta">
    The link works once and then expires after
    {{ (int) config('recruitment.candidate_access.token_minutes', 30) }} minutes. If it has run out,
    just ask for another one. If you have not applied for anything yet, you can
    <a href="{{ route('careers.index') }}">see what is open</a>.
</p>

@endsection
