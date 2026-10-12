@extends('layouts.candidate', ['title' => 'Confirm sign-in — Naija Virtual Notary'])

{{--
    The button that spends the token.

    This page is reached by a GET, and a GET here does NOT consume anything —
    corporate mail gateways and chat previews fetch every link in an email,
    often before the recipient has seen it, and a one-use token spent by a
    scanner is a token the candidate never gets to use. The POST below is what
    actually redeems it.

    The token is in the URL, so the layout sets referrer: no-referrer and this
    page carries no outbound links at all while it is valid.
--}}

@section('shell-class', 'narrow')

@section('content')

@if ($valid)
    <h1>Sign in to your application</h1>
    <p class="lede">
        You are one tap away. We ask you to confirm because email systems sometimes open links
        automatically, and this one only works once.
    </p>

    <div class="card">
        <form method="POST" action="{{ route('candidate.login.redeem', $token) }}">
            @csrf
            <button type="submit" class="btn">Sign in now</button>
        </form>
    </div>
@else
    <h1>That link has expired</h1>
    <p class="lede">
        Sign-in links work once and only for a short while, so this one can no longer be used.
        Nothing is wrong with your application — ask for a fresh link and carry on.
    </p>

    <div class="card">
        <form method="POST" action="{{ route('candidate.login.request') }}">
            @csrf

            <div style="margin-bottom:18px;">
                <label for="email">Email address you applied with</label>
                <input type="email" id="email" name="email" required autocomplete="email" autofocus>
            </div>

            <button type="submit" class="btn">Email me a new link</button>
        </form>
    </div>
@endif

@endsection
