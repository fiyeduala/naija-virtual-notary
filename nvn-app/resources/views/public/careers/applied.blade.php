@extends('layouts.public', [
    'title' => 'Application received | Naija Virtual Notary',
    'description' => 'Your application to Naija Virtual Notary has been received.',
])

{{--
    The confirmation page.

    It shows the role and the reference and nothing else. No name, no email, no
    "welcome back" — because this URL carries a reference in it, and a reference
    that was forwarded, guessed or found in a browser history should not tell
    the finder a single thing about the person who applied.

    It also says what happens next in real terms, including that the email may
    take a minute, because the outbox is drained by a cron and a candidate
    refreshing their inbox for ten seconds will otherwise assume it failed.
--}}

@push('styles')
<style>
    .done-wrap { max-width: 640px; margin: 0 auto; text-align: center; }
    .done-mark {
        width: 68px; height: 68px; border-radius: 50%; background: var(--brand-light);
        display: flex; align-items: center; justify-content: center; margin: 0 auto 26px;
    }
    .done-mark svg { width: 34px; height: 34px; stroke: var(--brand-dark); }
    .done-wrap h1 { font-size: clamp(26px, 3.4vw, 38px); font-weight: 800; margin-bottom: 14px; }
    .done-wrap .lede { font-size: 17px; color: var(--muted); line-height: 1.7; margin-bottom: 32px; }

    .ref-card {
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
        padding: 26px; margin-bottom: 26px; text-align: left;
    }
    .ref-card dt {
        font-size: 12px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
        color: var(--muted); margin-bottom: 6px;
    }
    .ref-card dd { margin: 0 0 18px; font-size: 16px; }
    .ref-card dd:last-child { margin-bottom: 0; }
    .ref-card .ref {
        font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace; font-size: 21px;
        font-weight: 700; letter-spacing: .06em; color: var(--brand-dark);
    }

    .next { text-align: left; background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--radius); padding: 26px; margin-bottom: 26px; }
    .next h2 { font-size: 17px; font-weight: 700; margin-bottom: 14px; }
    .next ol { margin: 0 0 0 20px; }
    .next li { font-size: 15px; line-height: 1.75; color: #3d4a57; margin-bottom: 9px; }
    .next li:last-child { margin-bottom: 0; }

    .warn {
        text-align: left; font-size: 14px; color: var(--muted); line-height: 1.7;
        border-left: 3px solid var(--brand); padding-left: 16px;
    }
    .warn strong { color: var(--ink); }
</style>
@endpush

@section('content')

<section class="section">
    <div class="done-wrap">

        <div class="done-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 6 9 17l-5-5"/>
            </svg>
        </div>

        <h1>Your application is in</h1>
        <p class="lede">
            It is saved and it is with us. Please keep the reference below — it is how we
            find your application if you need to get in touch.
        </p>

        <dl class="ref-card">
            <dt>Role applied for</dt>
            <dd>{{ $vacancy->title }}</dd>

            <dt>Your reference</dt>
            <dd class="ref">{{ $reference }}</dd>
        </dl>

        <div class="next">
            <h2>What happens next</h2>
            <ol>
                <li>A confirmation email is on its way to the address you gave. It can take a
                    minute or two, and it sometimes lands in spam.</li>
                <li>A member of our team reads your application. We do not use software to score
                    or filter people out — a person reads every one.</li>
                <li>If we would like to take it further, we will email you an invitation to a
                    short written exercise for this role.</li>
                <li>You can check on your application at any time by asking for a sign-in link
                    at <a href="{{ route('candidate.login') }}" style="color:var(--brand-dark);font-weight:600;">your
                    applicant page</a>.</li>
            </ol>
        </div>

        <p class="warn">
            <strong>We will never ask you to pay anything.</strong> Not to apply, not to be
            interviewed, and not to be trained. We also will not ask for your bank details or
            your identity documents at this stage. If anyone contacts you asking for money on
            our behalf, it is not us.
        </p>

        <p style="margin-top:32px;">
            <a href="{{ route('careers.index') }}" class="btn btn-outline">See our other openings</a>
        </p>

    </div>
</section>

@endsection
