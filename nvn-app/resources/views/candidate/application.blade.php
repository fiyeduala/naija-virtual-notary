@extends('layouts.candidate', [
    'title' => ($application->vacancy?->title ?? 'Your application') . ' — Naija Virtual Notary',
])

{{--
    One application, from the candidate's side.

    Facts about their own progress, and nothing about anybody's opinion of them.
    Interview feedback, reviewer scores, reviewer names and the reasoning behind
    a decision are not loaded onto this page at all — not hidden by a condition
    somebody could later invert.
--}}

@section('content')

@php
    $zone = $candidate->timezone ?? config('recruitment.timezone');
    $tz   = fn ($value) => $value?->timezone($zone);
@endphp

<p class="meta" style="margin-bottom:14px;">
    <a href="{{ route('candidate.home') }}">&larr; All your applications</a>
</p>

<h1>{{ $application->vacancy?->title ?? 'Your application' }}</h1>

<p class="lede" style="margin-bottom:22px;">
    Reference <span class="ref">{{ $application->reference }}</span>
    @if ($application->applied_at)
        &middot; applied {{ $tz($application->applied_at)->format('j F Y') }}
    @endif
</p>

<div class="card card-tight" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
    <span class="pill @if ($application->isRejected() || $application->isWithdrawn()) pill-grey @endif">
        {{ $application->candidateStageLabel() }}
    </span>

    @if ($application->isWithdrawn())
        <span class="meta">You withdrew this application.</span>
    @elseif ($application->isRejected())
        <span class="meta">We are not taking this one further. Thank you for the time you put in.</span>
    @endif
</div>

{{-- ── The exercise ────────────────────────────────────────────────── --}}
@if ($assignment)
    <div class="card">
        <h2>Your exercise</h2>

        @if ($assignment->acceptsChanges())
            <p style="font-size:15px;line-height:1.7;margin-bottom:8px;">
                This is waiting for you.
                @if ($assignment->dueForHumans($zone))
                    It is due by <strong>{{ $assignment->dueForHumans($zone) }}</strong>.
                @endif
                @if ($assignment->effortLine())
                    {{ $assignment->effortLine() }}
                @endif
            </p>

            @if ($assignment->isOverdue())
                <p class="meta" style="margin-bottom:14px;">
                    The due date has passed. You can still send it — we will see when it arrived.
                </p>
            @endif

            <a href="{{ route('candidate.assessment', $assignment) }}" class="btn">
                {{ $assignment->draftAnswers()->isNotEmpty() ? 'Carry on with the exercise' : 'Start the exercise' }}
            </a>
        @else
            <p style="font-size:15px;line-height:1.7;">
                Submitted{{ $assignment->submitted_at ? ' on ' . $tz($assignment->submitted_at)->format('j F Y \a\t H:i') : '' }}.
                A member of our team will read it. You can
                <a href="{{ route('candidate.assessment', $assignment) }}">see what you sent</a>.
            </p>
        @endif
    </div>
@endif

{{-- ── The interview ───────────────────────────────────────────────── --}}
@if ($interview)
    <div class="card">
        <h2>Your interview</h2>

        <p style="font-size:15px;line-height:1.8;margin-bottom:6px;">
            <strong>{{ $interview->timeForHumans($zone) }}</strong>
            @if ($interview->duration_minutes)
                &middot; about {{ $interview->duration_minutes }} minutes
            @endif
        </p>

        @if (filled($interview->candidate_instructions))
            <p style="font-size:15px;line-height:1.7;margin-bottom:12px;">
                {!! nl2br(e($interview->candidate_instructions)) !!}
            </p>
        @endif

        @if (filled($interview->meeting_url))
            <p style="margin-bottom:16px;">
                <a href="{{ $interview->meeting_url }}" rel="noopener noreferrer nofollow" target="_blank" class="btn btn-outline">
                    Join the call
                </a>
            </p>
        @endif

        @if ($interview->candidate_acknowledged_at)
            <p class="meta">You confirmed you can make this on
                {{ $tz($interview->candidate_acknowledged_at)->format('j M Y') }}.</p>
        @else
            <form method="POST" action="{{ route('candidate.interview.acknowledge', $interview) }}"
                  style="margin-bottom:18px;">
                @csrf
                <button type="submit" class="btn btn-quiet">Yes, I can make this time</button>
            </form>
        @endif

        @if (filled($interview->reschedule_request_note))
            <p class="meta" style="margin-top:12px;">
                You have asked us for a different time. We will confirm by email — nothing changes
                until we do.
            </p>
        @else
            <details style="margin-top:14px;">
                <summary style="cursor:pointer;font-size:14.5px;font-weight:600;">
                    I need a different time
                </summary>
                <form method="POST" action="{{ route('candidate.interview.reschedule', $interview) }}"
                      style="margin-top:14px;">
                    @csrf
                    <label for="note">When would suit you better?</label>
                    <textarea id="note" name="note" required maxlength="1000" style="min-height:90px;"
                              placeholder="Any afternoon next week, for example."></textarea>
                    <button type="submit" class="btn btn-quiet" style="margin-top:12px;">Send the request</button>
                </form>
            </details>
        @endif
    </div>
@endif

{{-- ── The offer ───────────────────────────────────────────────────── --}}
@if ($offer)
    <div class="card">
        <h2>Your offer</h2>

        <p style="font-size:15px;line-height:1.7;margin-bottom:14px;">
            @if ($offer->status === \App\Models\Offer::ACCEPTED)
                You accepted this offer{{ $offer->responded_at ? ' on ' . $tz($offer->responded_at)->format('j F Y') : '' }}.
            @elseif ($offer->status === \App\Models\Offer::DECLINED)
                You declined this offer{{ $offer->responded_at ? ' on ' . $tz($offer->responded_at)->format('j F Y') : '' }}.
            @else
                We have made you an offer. Please read it and let us know either way.
            @endif
        </p>

        <a href="{{ route('candidate.offer', $offer) }}" class="btn">
            {{ $offer->awaitingResponse() ? 'Read the offer' : 'See the offer' }}
        </a>
    </div>
@endif

{{-- ── What is still needed ────────────────────────────────────────── --}}
@php
    // Waived items are not the candidate's business: they were never asked for.
    $items = $checklist->reject(fn ($item) => $item->status === \App\Models\OnboardingItem::WAIVED);
@endphp

@if ($items->isNotEmpty())
    <div class="card">
        <h2>What we still need</h2>

        <p class="meta" style="margin-bottom:18px;">
            These are only asked for at this stage, now that there is an offer on the table.
            Everything you send here is stored privately and is seen only by the person handling
            your onboarding.
        </p>

        @foreach ($items as $item)
            @php
                $settled = $item->status === \App\Models\OnboardingItem::VERIFIED;
                $sent    = in_array($item->status, [
                    \App\Models\OnboardingItem::SUBMITTED,
                    \App\Models\OnboardingItem::VERIFIED,
                ], true);
            @endphp

            <div style="padding:16px 0;border-top:1px solid var(--line);">
                <p style="font-size:15px;font-weight:600;line-height:1.6;margin-bottom:6px;">
                    {{ $item->label }}
                    @unless ($item->is_required)
                        <span class="meta" style="font-weight:400;">(optional)</span>
                    @endunless
                </p>

                <p style="margin-bottom:12px;">
                    <span class="pill @if ($settled) @elseif ($sent) pill-grey @else pill-warn @endif">
                        @if ($settled)
                            Received and checked
                        @elseif ($sent)
                            Received, being checked
                        @elseif ($item->status === \App\Models\OnboardingItem::NOT_VERIFIED)
                            We need another look at this
                        @else
                            Waiting on you
                        @endif
                    </span>
                </p>

                {{-- What is being asked for and why, written when the
                     checklist was built. Shown whatever the status is: a
                     person who has already sent something still has a
                     right to read what they were asked for. --}}
                @if (filled($item->instructions))
                    <p style="margin-bottom:12px;line-height:1.7;">{!! nl2br(e($item->instructions)) !!}</p>
                @endif

                @if ($item->status === \App\Models\OnboardingItem::NOT_VERIFIED && filled($item->note))
                    <p class="meta" style="margin-bottom:12px;">{!! nl2br(e($item->note)) !!}</p>
                @endif

                @if (! $settled)
                    @if ($item->kind === \App\Models\OnboardingItem::KIND_DOCUMENT)
                        <form method="POST" action="{{ route('candidate.checks.upload', $item) }}"
                              enctype="multipart/form-data">
                            @csrf
                            <label for="file-{{ $item->id }}">
                                Choose a file
                                <span class="meta" style="font-weight:400;">
                                    ({{ \App\Support\UploadGuard::maxMb('onboarding') }} MB maximum)
                                </span>
                            </label>
                            <input type="file" id="file-{{ $item->id }}" name="file" required
                                   accept="{{ \App\Support\UploadGuard::acceptAttribute('onboarding') }}">

                            <label for="note-{{ $item->id }}" style="margin-top:14px;">
                                Anything we should know about it? <span class="meta" style="font-weight:400;">(optional)</span>
                            </label>
                            <textarea id="note-{{ $item->id }}" name="note" maxlength="1000"
                                      style="min-height:70px;"></textarea>

                            <button type="submit" class="btn btn-quiet" style="margin-top:12px;">
                                {{ $sent ? 'Send a replacement' : 'Send this' }}
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('candidate.checks.answer', $item) }}">
                            @csrf
                            <label for="answer-{{ $item->id }}">Your answer</label>
                            <textarea id="answer-{{ $item->id }}" name="note" required maxlength="4000"
                                      style="min-height:110px;">{{ $sent ? $item->note : '' }}</textarea>

                            @if ($item->kind === \App\Models\OnboardingItem::KIND_REFERENCE)
                                <p class="meta" style="margin-top:10px;">
                                    Please give their name, their role and how to reach them &mdash; and only
                                    name somebody who has agreed to be contacted. We will not contact anybody
                                    until a member of our team decides to, and never without that agreement.
                                </p>
                            @endif

                            <button type="submit" class="btn btn-quiet" style="margin-top:12px;">
                                {{ $sent ? 'Update your answer' : 'Send this' }}
                            </button>
                        </form>
                    @endif
                @endif
            </div>
        @endforeach
    </div>
@endif

{{-- ── Your files ──────────────────────────────────────────────────── --}}
@if ($documents->isNotEmpty())
    <div class="card">
        <h2>Your files</h2>

        <p class="meta" style="margin-bottom:14px;">
            Downloading asks us who you are first, every time. We never put a document link
            in an email, because an email outlives the inbox it arrived in.
        </p>

        @foreach ($documents as $document)
            <p style="font-size:14.5px;line-height:1.8;">
                <a href="{{ route('candidate.document', $document) }}">
                    {{ $document->original_name ?: $document->kindLabel() }}
                </a>
                <span class="meta">&middot; {{ $document->kindLabel() }} &middot; {{ $document->sizeForHumans() }}</span>
            </p>
        @endforeach
    </div>
@endif

{{-- ── Progress ────────────────────────────────────────────────────── --}}
<div class="card">
    <h2>Where things stand</h2>

    <p class="meta" style="margin-bottom:16px;">
        Each step below is dated from when it happened. We do not publish how long a
        step takes, because it depends on how many people have applied.
    </p>

    @php
        // Candidate-safe: the stage reached and when. No actor, no reason, no note.
        // By id, not by created_at. Two moves can share a second, sortBy is
        // stable, and the relation hands them over newest-first — so sorting
        // on the timestamp alone showed a tied pair to the candidate in
        // reverse. The id is the order they actually happened in.
        $history = $application->stageEvents
            ->sortBy('id')
            ->filter(fn ($event) => $event->to_stage instanceof \App\Enums\ApplicationStage);
    @endphp

    @forelse ($history as $event)
        <p style="font-size:14.5px;line-height:1.8;display:flex;gap:10px;flex-wrap:wrap;">
            <span style="min-width:170px;font-weight:600;">
                {{ $event->to_stage->candidateLabel() }}
            </span>
            <span class="meta">{{ $tz($event->created_at)->format('j M Y, H:i') }}</span>
        </p>
    @empty
        <p style="font-size:14.5px;line-height:1.8;">
            <span style="font-weight:600;">{{ $application->candidateStageLabel() }}</span>
            @if ($application->applied_at)
                <span class="meta">&middot; {{ $tz($application->applied_at)->format('j M Y, H:i') }}</span>
            @endif
        </p>
    @endforelse

    <p class="meta" style="margin-top:14px;">
        Times are shown in {{ $zone }}.
    </p>
</div>

{{-- ── Withdrawing ─────────────────────────────────────────────────── --}}
@if ($application->isActive())
    <div class="card">
        <h2>Withdraw this application</h2>

        <p style="font-size:14.5px;line-height:1.7;margin-bottom:6px;">
            If you have changed your mind, you can stop here. We will cancel anything we had
            queued to send you, and nobody will chase you about it.
        </p>
        <p class="meta" style="margin-bottom:18px;">
            This cannot be undone from your side. You are welcome to apply again for this
            or another role afterwards.
        </p>

        <details>
            <summary style="cursor:pointer;font-size:14.5px;font-weight:600;">
                I want to withdraw
            </summary>

            <form method="POST" action="{{ route('candidate.withdraw', $application) }}"
                  style="margin-top:16px;">
                @csrf

                <label for="reason">
                    Would you tell us why? <span class="meta" style="font-weight:400;">(optional)</span>
                </label>
                <textarea id="reason" name="reason" maxlength="1000" style="min-height:80px;"></textarea>

                <div class="check" style="margin-top:16px;">
                    <input type="checkbox" id="confirm" name="confirm" value="1" required>
                    <label for="confirm">
                        I understand this withdraws my application for
                        {{ $application->vacancy?->title ?? 'this role' }}.
                    </label>
                </div>

                <button type="submit" class="btn btn-danger">Withdraw my application</button>
            </form>
        </details>
    </div>
@endif

@endsection
