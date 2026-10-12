@extends('layouts.candidate', ['title' => 'Your applications — Naija Virtual Notary'])

{{--
    Everything this candidate has applied for.

    Each row shows the role, the reference, the candidate-facing stage, and
    whether anything is waiting on them. What it does not show is any reviewer's
    view of them: no score, no note, no reviewer name, no reason behind a
    decision. Those live on records the controller never loads.
--}}

@section('content')

<h1>Your applications</h1>
<p class="lede">
    Everything you have applied for with us, and anything we are waiting on from you.
</p>

@forelse ($applications as $application)
    @php
        $assignment = $application->currentAssignment();
        $waiting    = $assignment && $assignment->acceptsChanges();
    @endphp

    <div class="card">
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;justify-content:space-between;">
            <div style="min-width:0;flex:1 1 320px;">
                <h2 style="margin-bottom:8px;">
                    <a href="{{ route('candidate.application', $application) }}">
                        {{ $application->vacancy?->title ?? 'Application' }}
                    </a>
                </h2>

                <p class="meta">
                    Reference <span class="ref">{{ $application->reference }}</span>
                    @if ($application->applied_at)
                        &middot; applied {{ $application->applied_at->timezone(config('recruitment.timezone'))->format('j M Y') }}
                    @endif
                </p>
            </div>

            <span class="pill @if ($application->isRejected() || $application->isWithdrawn()) pill-grey @endif">
                {{ $application->candidateStageLabel() }}
            </span>
        </div>

        @if ($waiting)
            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line);">
                <p style="font-size:14.5px;line-height:1.7;margin-bottom:12px;">
                    <strong>There is an exercise waiting for you.</strong>
                    @if ($assignment->dueForHumans($candidate->timezone))
                        It is due by {{ $assignment->dueForHumans($candidate->timezone) }}.
                    @endif
                </p>
                <a href="{{ route('candidate.assessment', $assignment) }}" class="btn">Open the exercise</a>
            </div>
        @endif
    </div>
@empty
    <div class="card" style="text-align:center;padding:44px 24px;">
        <h2>Nothing here yet</h2>
        <p class="meta" style="max-width:380px;margin:0 auto 20px;">
            There are no applications against this email address. If you applied with a different
            address, ask for a link using that one instead.
        </p>
        <a href="{{ route('careers.index') }}" class="btn btn-outline">See what is open</a>
    </div>
@endforelse

@endsection
