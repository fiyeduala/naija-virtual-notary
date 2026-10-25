@extends('layouts.public', [
    'title' => 'One moment | Naija Virtual Notary',
    'description' => 'That form has been sent a few times in a row. Here is how long to wait.',
])

{{--
    What a throttled visitor sees.

    Until this existed they got the framework's own 429: the words TOO MANY
    REQUESTS on a bare white screen, with nothing about what they had done,
    whether their work was lost, or when to try again. Somebody halfway
    through signing up reads that as the site being broken, and most of them
    do not come back to find out.

    So it says the three things a person in that position actually wants: that
    nothing is wrong with what they sent, how long the wait is, and why it may
    have happened to them when they have only tried once -- which, on a shared
    office or mobile connection, is the usual case. The wait is read off the
    Retry-After header the limiter already sets and rounded up to whole
    minutes, because "try again in 47 seconds" invites counting.

    No support address here: the site publishes none, and inventing one puts a
    mailbox nobody reads on an error page.
--}}

@push('styles')
<style>
    .wait-wrap { max-width: 540px; margin: 0 auto; }
    .wait-card {
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--radius); padding: 30px 32px; margin-top: 28px;
    }
    .wait-card p { margin: 0 0 14px; line-height: 1.75; }
    .wait-card p:last-child { margin-bottom: 0; }
    .wait-when {
        font-size: 17px; font-weight: 700; color: var(--brand-dark);
    }
    .wait-why { font-size: 13.5px; color: var(--muted); }
    .wait-actions { margin-top: 26px; display: flex; gap: 12px; flex-wrap: wrap; }
    .wait-actions a {
        padding: 12px 20px; border-radius: 10px; font-weight: 600;
        font-size: 15px; text-decoration: none; display: inline-block;
    }
    .wait-go { background: var(--brand); color: #fff; }
    .wait-go:hover { background: var(--brand-dark); }
    .wait-home { border: 1px solid var(--line); color: var(--muted); }
</style>
@endpush

@section('content')
<section class="section">
    <div class="wait-wrap">
        <div class="section-label">Just a pause</div>
        <h1 class="section-title">One moment</h1>

        <div class="wait-card">
            <p>
                That form has been sent a few times in a row from this
                connection, so we have paused it for a short while. Nothing has
                gone wrong, and nothing you sent has been lost.
            </p>

            @php
                $seconds = (int) ($exception?->getHeaders()['Retry-After'] ?? 0);
                $minutes = (int) ceil($seconds / 60);
            @endphp

            <p class="wait-when">
                @if ($minutes === 1)
                    Please try again in about a minute.
                @elseif ($minutes > 1)
                    Please try again in about {{ $minutes }} minutes.
                @else
                    Please try again shortly.
                @endif
            </p>

            <p class="wait-why">
                If this is the first time you have tried, you are most likely
                sharing a connection — an office, a business centre, or a
                mobile network — with someone who reached the limit before you.
                Waiting is all that is needed.
            </p>
        </div>

        <div class="wait-actions">
            <a class="wait-go" href="{{ url()->previous() }}">Go back and try again</a>
            <a class="wait-home" href="{{ route('home') }}">Home</a>
        </div>
    </div>
</section>
@endsection
