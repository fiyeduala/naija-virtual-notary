@extends('layouts.candidate', [
    'title' => 'Your exercise — Naija Virtual Notary',
])

{{--
    The exercise.

    Every task on this page comes off $assignment->tasks(), which is the
    snapshot taken when the invitation was sent. Nobody editing the template
    today can change what somebody is halfway through answering.

    The brief is a short, approved description of the company and the process.
    It is here because asking somebody to answer a customer enquiry without
    telling them how the service works tests their guesswork, not their work.
--}}

@section('content')

@php
    $scenarioHint = [
        'long_text'  => null,
        'short_text' => null,
        'select'     => null,
        'yes_no'     => null,
        'url'        => 'A full address starting with https://',
    ];
@endphp

<p class="meta" style="margin-bottom:14px;">
    <a href="{{ route('candidate.application', $application) }}">&larr; Back to your application</a>
</p>

<h1>{{ $application->vacancy?->title ?? 'Your exercise' }}</h1>

<p class="lede" style="margin-bottom:22px;">
    Reference <span class="ref">{{ $application->reference }}</span>
    @if ($assignment->effortLine())
        &middot; {{ $assignment->effortLine() }} of work
    @endif
</p>

{{-- ── Where this stands ───────────────────────────────────────────── --}}
<div class="card card-tight" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
    @if ($assignment->isSubmitted())
        <span class="pill">Submitted</span>
        <span class="meta">
            Received{{ $assignment->submitted_at ? ' on ' . $assignment->submitted_at->timezone($candidate->timezone ?? config('recruitment.timezone'))->format('j F Y \a\t H:i') : '' }}.
            Somebody on our team will read it.
        </span>
    @elseif ($assignment->isOverdue())
        <span class="pill pill-warn">Past the due date</span>
        <span class="meta">
            The form below is closed. Nothing has been decided about your application —
            if you need more time, reply to the email that invited you and somebody can
            give you longer.
        </span>
    @elseif ($due)
        <span class="pill">Due {{ $due }}</span>
        @if ($assignment->wasExtended())
            <span class="meta">This deadline has been extended for you.</span>
        @endif
    @endif
</div>

{{-- ── The brief ───────────────────────────────────────────────────── --}}
@if (filled($assignment->brief()))
    <div class="card">
        <h2>Before you start</h2>
        <div style="font-size:15px;line-height:1.75;">
            {!! nl2br(e($assignment->brief())) !!}
        </div>
    </div>
@endif

{{-- ── What we are looking for ─────────────────────────────────────── --}}
@if ($assignment->rubric())
    <div class="card">
        <h2>What we are looking for</h2>

        <p class="meta" style="margin-bottom:14px;">
            A member of our team reads and scores every answer. Nothing here is marked by
            software, and nobody is filtered out automatically.
        </p>

        <ul style="margin:0 0 0 20px;font-size:14.5px;line-height:1.8;">
            @foreach ($assignment->rubric() as $criterion)
                <li>{{ $criterion['label'] ?? '' }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- The same sentence the draft route and the submit route would give her,
     read off the assignment rather than written a third time here. --}}
@if (! $editable && ! $assignment->isSubmitted())
    <div class="card card-tight">
        <p style="font-size:15px;line-height:1.7;">
            {{ $assignment->closedReason() }}
        </p>
    </div>
@endif

{{-- ── The tasks ───────────────────────────────────────────────────── --}}
<form method="POST" action="{{ route('candidate.assessment.submit', $assignment) }}" id="exercise">
    @csrf

    @forelse ($tasks as $index => $task)
        @php
            $key      = (string) ($task['key'] ?? 't' . ($index + 1));
            $type     = (string) ($task['type'] ?? 'long_text');
            $required = (bool) ($task['required'] ?? true);
            $field    = 'answers.' . $key;
            $current  = old('answers.' . $key, $answers[$key]->answer ?? '');
        @endphp

        <div class="card">
            <h2>
                {{ $index + 1 }}. {{ $task['label'] ?? '' }}
                @if (! $required)
                    <span class="meta" style="font-weight:400;">(optional)</span>
                @endif
            </h2>

            @if (filled($task['help'] ?? ''))
                <p class="meta" style="margin-bottom:14px;">{!! nl2br(e($task['help'])) !!}</p>
            @endif

            <label for="task-{{ $key }}" class="@if (blank($task['help'] ?? '')) @endif">
                Your answer
                @if ($required)
                    <span aria-hidden="true">*</span>
                @endif
            </label>

            @if ($type === 'select' && ! empty($task['options']))
                <select id="task-{{ $key }}" name="answers[{{ $key }}]"
                        @disabled(! $editable)
                        @if ($errors->has($field)) aria-invalid="true" @endif>
                    <option value="">Choose one</option>
                    @foreach ($task['options'] as $option)
                        <option value="{{ $option }}" @selected($current === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            @elseif ($type === 'yes_no')
                <select id="task-{{ $key }}" name="answers[{{ $key }}]"
                        @disabled(! $editable)
                        @if ($errors->has($field)) aria-invalid="true" @endif>
                    <option value="">Choose one</option>
                    <option value="Yes" @selected($current === 'Yes')>Yes</option>
                    <option value="No" @selected($current === 'No')>No</option>
                </select>
            @elseif ($type === 'short_text')
                <input type="text" id="task-{{ $key }}" name="answers[{{ $key }}]"
                       value="{{ $current }}" maxlength="500"
                       @disabled(! $editable)
                       @if ($errors->has($field)) aria-invalid="true" @endif>
            @elseif ($type === 'url')
                <input type="text" id="task-{{ $key }}" name="answers[{{ $key }}]"
                       value="{{ $current }}" maxlength="500"
                       placeholder="https://"
                       @disabled(! $editable)
                       @if ($errors->has($field)) aria-invalid="true" @endif>
                <p class="meta" style="margin-top:8px;">{{ $scenarioHint['url'] }}.</p>
            @else
                <textarea id="task-{{ $key }}" name="answers[{{ $key }}]" maxlength="20000"
                          @disabled(! $editable)
                          @if ($errors->has($field)) aria-invalid="true" @endif>{{ $current }}</textarea>
            @endif

            @error($field)
                <p class="meta" style="color:#8a1a14;margin-top:8px;">{{ $message }}</p>
            @enderror
        </div>
    @empty
        <div class="card card-tight">
            <p style="font-size:15px;line-height:1.7;">
                There are no tasks on this exercise. That is our mistake, not yours &mdash; please
                reply to the email we sent you and we will sort it out.
            </p>
        </div>
    @endforelse

    {{-- ── The introduction ────────────────────────────────────────── --}}
    @if ($application->vacancy?->intro_video_enabled)
        @php $introRequired = (bool) $application->vacancy->intro_video_required; @endphp

        <div class="card">
            <h2>
                A short introduction
                @unless ($introRequired)
                    <span class="meta" style="font-weight:400;">(optional)</span>
                @endunless
            </h2>

            <p style="font-size:14.5px;line-height:1.75;margin-bottom:8px;">
                If you would like to, record 60 to 120 seconds introducing yourself and paste
                the link below. An unlisted YouTube video or any private link is fine.
            </p>
            <p class="meta" style="margin-bottom:8px;">
                Please be aware that an unlisted link is not a private one: anybody who gets
                hold of the address can open it. We do not open the link automatically and we
                do not play it on this page &mdash; a member of our team opens it themselves.
            </p>
            <p class="meta" style="margin-bottom:18px;">
                You do not have to post anything publicly, tag us, or follow any of our
                accounts. None of that is scored.
            </p>

            <label for="intro_video_url">
                A link to your video
                @unless ($introRequired)
                    <span class="meta" style="font-weight:400;">(optional)</span>
                @endunless
            </label>
            <input type="text" id="intro_video_url" name="intro_video_url"
                   value="{{ old('intro_video_url', $assignment->intro_video_url) }}"
                   maxlength="500" placeholder="https://"
                   @disabled(! $editable)
                   @if ($errors->has('intro_video_url')) aria-invalid="true" @endif>

            @error('intro_video_url')
                <p class="meta" style="color:#8a1a14;margin-top:8px;">{{ $message }}</p>
            @enderror

            <label for="intro_alternative" style="margin-top:20px;">
                Or write it instead
            </label>
            <p class="meta" style="margin:-3px 0 8px;">
                @if ($introRequired)
                    Either one is enough &mdash; a written introduction is accepted in place of a
                    video and is not scored any lower. Choose whichever suits you.
                @else
                    If you would rather not record anything, write a few lines here instead.
                @endif
            </p>
            <textarea id="intro_alternative" name="intro_alternative" maxlength="8000"
                      style="min-height:120px;"
                      @disabled(! $editable)>{{ old('intro_alternative', $assignment->intro_alternative) }}</textarea>
        </div>
    @endif

    {{-- ── Saving and sending ──────────────────────────────────────── --}}
    @if ($editable)
        <div class="card">
            <p class="meta" style="margin-bottom:18px;">
                <strong>Save as you go.</strong> Saving keeps what you have typed and changes
                nothing else &mdash; you can come back as many times as you like. Sending it is
                final: after that you cannot edit your answers unless somebody on our team
                reopens the exercise for you.
            </p>

            <div style="display:flex;flex-wrap:wrap;gap:12px;">
                <button type="submit" class="btn" id="send">Send my answers</button>

                {{-- A second button posting to the draft route. Two routes, because
                     "save" and "this is my final answer" are different acts. --}}
                <button type="submit" class="btn btn-quiet" id="save"
                        formaction="{{ route('candidate.assessment.draft', $assignment) }}"
                        formnovalidate>
                    Save and come back later
                </button>
            </div>
        </div>
    @endif
</form>

@if ($assignment->isSubmitted())
    <div class="card card-tight">
        <p class="meta">
            This is what you sent us. If something needs correcting, reply to the email we
            sent you &mdash; a member of our team can reopen the exercise, and your earlier
            answers are kept either way.
        </p>
    </div>
@endif

<p class="meta" style="text-align:center;">
    Everything in this exercise is made up for the purpose of it. No part of it is real
    client work, and nothing you write here is used for anything other than deciding on
    your application.
</p>

@if ($editable)
    <script>
        // Confirm before the final send, because it cannot be undone from this
        // side. The save button is deliberately exempt.
        (function () {
            var form = document.getElementById('exercise');
            var send = document.getElementById('send');
            if (!form || !send) { return; }

            var sending = false;

            send.addEventListener('click', function () { sending = true; });

            form.addEventListener('submit', function (event) {
                if (!sending) { return; }

                if (!window.confirm('Send your answers now? You will not be able to edit them afterwards.')) {
                    event.preventDefault();
                    sending = false;
                    return;
                }

                send.disabled = true;
                send.textContent = 'Sending...';
            });
        })();
    </script>
@endif

@endsection
