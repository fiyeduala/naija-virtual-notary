@extends('layouts.public', [
    'title' => 'Recruitment privacy notice | Naija Virtual Notary',
    'description' => 'What Naija Virtual Notary does with the information you send when you apply for a job with us.',
])

{{--
    The notice, rendered from plain text.

    Administrator-editable, so it is escaped paragraph by paragraph and no
    markup survives. An editable field that renders as HTML on a public page is
    a stored-XSS hole, and this one is reachable without signing in.
--}}

@push('styles')
<style>
    .policy-head { background: #0f1a0b; color: #fff; padding: 56px 24px 48px; text-align: center; }
    .policy-head h1 { font-size: clamp(26px, 3.4vw, 40px); font-weight: 800; color: #fff; margin-bottom: 12px; }
    .policy-head p { color: rgba(255,255,255,.7); font-size: 15px; }

    .policy { max-width: 760px; margin: 0 auto; }
    .policy h2 { font-size: 20px; font-weight: 700; margin: 36px 0 12px; }
    .policy h2:first-of-type { margin-top: 0; }
    .policy p { font-size: 16px; line-height: 1.8; color: #3d4a57; margin-bottom: 14px; }
    .policy .version {
        font-size: 13.5px; color: var(--muted); padding: 14px 18px; background: var(--surface);
        border: 1px solid var(--line); border-radius: var(--radius); margin-bottom: 32px;
    }
    .policy .missing {
        font-size: 14.5px; line-height: 1.7; padding: 16px 20px; background: #fff4e5;
        border: 1px solid #f0c589; color: #7a4b00; border-radius: var(--radius); margin-bottom: 28px;
    }
</style>
@endpush

@section('content')

<header class="policy-head">
    <h1>Recruitment privacy notice</h1>
    <p>What we do with the information you send when you apply for a job with us.</p>
</header>

<section class="section">
    <div class="policy">

        <p class="version">
            Version <strong>{{ \App\Support\RecruitmentPrivacyNotice::version() }}</strong>.
            When you tick the box on an application form, we record which version of this notice you
            read and when — so if we change it later, what you agreed to is still on record.
        </p>

        @if (\App\Support\RecruitmentPrivacyNotice::contactMissing())
            <div class="missing">
                We have not yet published an address for privacy requests on this page. If you need to
                reach us about your information in the meantime, please use the contact details on our
                main site.
            </div>
        @endif

        @foreach (\App\Support\RecruitmentPrivacyNotice::sections() as $section)
            @if ($section['heading'])
                <h2>{{ $section['heading'] }}</h2>
            @endif

            @foreach ($section['paragraphs'] as $paragraph)
                <p>{!! nl2br(e($paragraph)) !!}</p>
            @endforeach
        @endforeach

        <p style="margin-top:36px;">
            <a href="{{ route('careers.index') }}" class="btn btn-outline">Back to our openings</a>
        </p>

    </div>
</section>

@endsection
