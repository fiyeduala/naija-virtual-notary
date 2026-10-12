@extends('layouts.candidate', [
    'title' => 'Your offer — Naija Virtual Notary',
])

{{--
    The offer, and the candidate's answer to it.

    Stated on the page, not just in the controller: ticking the box and pressing
    the button records that this person agreed to the terms as they were issued.
    It is not an electronic signature and nothing here is certified. This is a
    notarization business, and the difference between a record of agreement and
    a signed instrument is the trade.

    Nothing on this page invents a term. The body, the pay, the start date and
    the compensation note are whatever somebody typed into the panel and
    approved; a field left blank does not render at all.
--}}

@section('content')

@php
    $zone     = config('recruitment.timezone');
    $answered = in_array($offer->status, [\App\Models\Offer::ACCEPTED, \App\Models\Offer::DECLINED], true);
    $accepted = $offer->status === \App\Models\Offer::ACCEPTED;
@endphp

<p class="meta" style="margin-bottom:14px;">
    <a href="{{ route('candidate.application', $application) }}">&larr; Back to your application</a>
</p>

<h1>Your offer</h1>

<p class="lede" style="margin-bottom:22px;">
    {{ $application->vacancy?->title ?? 'Role' }}
    &middot; reference <span class="ref">{{ $application->reference }}</span>
</p>

{{-- ── Where it stands ─────────────────────────────────────────────── --}}
<div class="card card-tight" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
    @if ($accepted)
        <span class="pill">Accepted</span>
    @elseif ($offer->status === \App\Models\Offer::DECLINED)
        <span class="pill pill-grey">Declined</span>
    @else
        <span class="pill pill-warn">Waiting on your answer</span>
    @endif

    @if ($answered && $offer->responded_at)
        <span class="meta">
            You answered on {{ $offer->responded_at->timezone($zone)->format('j F Y') }}.
        </span>
    @elseif ($offer->sent_at)
        <span class="meta">
            Sent to you on {{ $offer->sent_at->timezone($zone)->format('j F Y') }}.
        </span>
    @endif
</div>

{{-- ── The terms, exactly as issued ────────────────────────────────── --}}
<div class="card">
    <h2>The offer</h2>

    @if (filled($offer->body))
        <div style="font-size:15px;line-height:1.8;margin-bottom:18px;">
            {!! nl2br(e($offer->body)) !!}
        </div>
    @endif

    @if ($document)
        <p style="margin-bottom:18px;">
            <a href="{{ route('candidate.document', $document) }}" class="btn btn-outline">
                Download the offer document
            </a>
        </p>
        <p class="meta" style="margin-bottom:18px;">
            Downloading asks us who you are first. We do not attach it to an email, because
            an email outlives the inbox it arrived in.
        </p>
    @endif

    @unless (filled($offer->body) || $document)
        <p style="font-size:15px;line-height:1.7;margin-bottom:18px;">
            The written terms are not on this page yet. Please reply to the email we sent you
            and we will send them across before you answer.
        </p>
    @endunless

    @php
        $facts = array_filter([
            'Pay'              => $offer->salaryLine(),
            'Compensation'     => \App\Models\Vacancy::COMPENSATION_TYPES[$offer->compensation_type] ?? null,
            'Proposed start'   => $offer->start_date?->format('j F Y'),
            'Employment type'  => $application->vacancy?->employment_type,
            'Where you work'   => $application->vacancy?->location_eligibility,
            'Hours'            => $application->vacancy?->working_hours,
        ], fn ($value) => filled($value));
    @endphp

    @if ($facts !== [])
        <dl style="display:grid;grid-template-columns:auto 1fr;gap:10px 18px;font-size:14.5px;line-height:1.6;">
            @foreach ($facts as $term => $value)
                <dt style="font-weight:600;">{{ $term }}</dt>
                <dd>{{ $value }}</dd>
            @endforeach
        </dl>
    @endif

    @if (filled($offer->compensation_note))
        <p class="meta" style="margin-top:16px;">{!! nl2br(e($offer->compensation_note)) !!}</p>
    @endif
</div>

{{-- ── Answering ───────────────────────────────────────────────────── --}}
@if ($offer->awaitingResponse())
    <div class="card">
        <h2>Your answer</h2>

        <p style="font-size:14.5px;line-height:1.75;margin-bottom:8px;">
            Pressing the button below records your answer against this offer, with the date and
            time. Please read the terms above first, and ask us anything you are unsure of
            before you answer &mdash; just reply to the email we sent you.
        </p>

        <p class="meta" style="margin-bottom:22px;">
            <strong>This is a record of your answer, not a signature.</strong> Accepting here is
            not an electronic signature and nothing on this page is certified or notarized. If a
            signed document is needed, that is a separate document and we will send it to you.
        </p>

        <form method="POST" action="{{ route('candidate.offer.respond', $offer) }}">
            @csrf

            <fieldset style="border:none;margin-bottom:18px;">
                <legend style="font-size:14.5px;font-weight:600;margin-bottom:12px;">
                    What would you like to do?
                </legend>

                <div class="check">
                    <input type="radio" id="accept" name="decision" value="accept" required
                           @checked(old('decision') === 'accept')>
                    <label for="accept">I accept this offer as set out above.</label>
                </div>

                <div class="check">
                    <input type="radio" id="decline" name="decision" value="decline"
                           @checked(old('decision') === 'decline')>
                    <label for="decline">I am declining this offer.</label>
                </div>
            </fieldset>

            @error('decision')
                <p class="meta" style="color:#8a1a14;margin-bottom:14px;">{{ $message }}</p>
            @enderror

            <label for="note">
                Anything you would like to add?
                <span class="meta" style="font-weight:400;">(optional)</span>
            </label>
            <textarea id="note" name="note" maxlength="2000" style="min-height:90px;">{{ old('note') }}</textarea>

            <div class="check" style="margin-top:20px;">
                <input type="checkbox" id="confirm" name="confirm" value="1" required
                       @if ($errors->has('confirm')) aria-invalid="true" @endif>
                <label for="confirm">
                    I have read the terms above and I understand this records my answer. I
                    understand it is not a signature.
                </label>
            </div>

            @error('confirm')
                <p class="meta" style="color:#8a1a14;margin-bottom:14px;">{{ $message }}</p>
            @enderror

            <button type="submit" class="btn">Record my answer</button>
        </form>
    </div>
@elseif ($answered)
    <div class="card card-tight">
        <p style="font-size:15px;line-height:1.7;">
            @if ($accepted)
                Your acceptance is recorded. It is a record of your answer, not a signature
                &mdash; if a signed document is needed we will send it to you separately.
                Accepting does not by itself start anything: a member of our team confirms
                the start once everything below is settled.
            @else
                Your decision is recorded and nobody will chase you about it. You are very
                welcome to apply again another time.
            @endif
        </p>

        @if (filled($offer->response_note))
            <p class="meta" style="margin-top:12px;">
                What you told us: {!! nl2br(e($offer->response_note)) !!}
            </p>
        @endif
    </div>
@endif

{{-- ── What is still needed ────────────────────────────────────────── --}}
@php
    $items = $checklist->reject(fn ($item) => $item->status === \App\Models\OnboardingItem::WAIVED);
@endphp

@if ($items->isNotEmpty())
    <div class="card">
        <h2>What we still need</h2>

        <p class="meta" style="margin-bottom:16px;">
            These are asked for only now, at this stage. You send them on your application
            page, where each one has its own box.
        </p>

        @foreach ($items as $item)
            <p style="font-size:14.5px;line-height:1.8;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <span style="font-weight:600;min-width:200px;">{{ $item->label }}</span>
                <span class="pill @if ($item->status === \App\Models\OnboardingItem::VERIFIED) @elseif ($item->status === \App\Models\OnboardingItem::SUBMITTED) pill-grey @else pill-warn @endif">
                    @if ($item->status === \App\Models\OnboardingItem::VERIFIED)
                        Received and checked
                    @elseif ($item->status === \App\Models\OnboardingItem::SUBMITTED)
                        Received, being checked
                    @elseif ($item->status === \App\Models\OnboardingItem::NOT_VERIFIED)
                        Needs another look
                    @else
                        Waiting on you
                    @endif
                </span>
            </p>
        @endforeach

        <p style="margin-top:18px;">
            <a href="{{ route('candidate.application', $application) }}" class="btn btn-quiet">
                Go and send these
            </a>
        </p>

        <p class="meta" style="margin-top:16px;">
            Anything you send here is stored privately, is never attached to an email, and is
            seen only by the person handling your onboarding. We ask for identity documents at
            this point and not before, and only for what we actually need.
        </p>
    </div>
@endif

<p class="meta" style="text-align:center;">
    We never ask you to pay anything &mdash; not a fee, not for training, not at any stage.
</p>

@endsection
