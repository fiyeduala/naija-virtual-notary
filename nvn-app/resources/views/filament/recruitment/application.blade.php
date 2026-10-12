@php
    /*
     | One application, in the order a reviewer actually reads it: who they are,
     | what they said, what they sent, how the exercise went, what anybody
     | thought of it, and then the record of what has been done about it.
     |
     | The one rule this file obeys everywhere: $documents has ALREADY been
     | filtered by ApplicationDocument::readableBy(), in getViewData(). This
     | template never decides who may see a file. Where something is withheld
     | it says so, because "no identity documents here" and "you are not
     | allowed to see the identity documents" are different facts and a
     | reviewer should not have to guess which one they are looking at.
     */
    $candidate  = $application->candidate;
    $assignment = $application->currentAssignment();
    $offer      = $application->offers->sortByDesc('id')->first();
    $scale      = $assignment?->scaleMax() ?? 5;

    $fact = fn ($value) => filled($value) ? $value : '—';
@endphp

<x-filament-panels::page>
    {{-- Where they are, and the three things most likely to be asked next. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-filament::section>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Stage</p>
            <p class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">
                {{ $application->stage->label() }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                @if ($application->stage_changed_at)
                    since {{ $application->stage_changed_at->timezone($zone)->format('j M Y, H:i') }}
                @else
                    no change recorded
                @endif
            </p>
        </x-filament::section>

        <x-filament::section>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Assessment</p>
            <p class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">
                {{ $assignment?->statusLabel() ?? 'Not invited' }}
            </p>
            <p class="mt-1 text-xs {{ $assignment?->isOverdue() ? 'text-danger-600 dark:text-danger-400' : 'text-gray-500 dark:text-gray-400' }}">
                {{ $assignment?->dueForHumans() ?? 'No deadline set' }}
            </p>
        </x-filament::section>

        <x-filament::section>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Scoring</p>
            <p class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">
                {{ $application->scores->whereNotNull('score')->count() }}
                <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                    of {{ max($application->scores->count(), 1) }} marks given
                </span>
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $application->scoringComplete()
                    ? 'Every assigned reviewer has finished.'
                    : 'Not every assigned reviewer has finished.' }}
            </p>
        </x-filament::section>

        <x-filament::section>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Came from</p>
            <p class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">
                {{ $application->sourceLabel() }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $fact($application->heard_about) }}
            </p>
        </x-filament::section>
    </div>

    {{-- The person, and the practical facts. Nothing here was asked for that
         the job does not need: no date of birth, no marital status, no
         photograph, and no identity document at this stage. --}}
    <x-filament::section>
        <x-slot name="heading">The applicant</x-slot>
        <x-slot name="description">
            Reference {{ $application->reference }} ·
            {{ $application->vacancy?->title }} ({{ $application->vacancy?->roleLabel() }})
        </x-slot>

        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['Name', $candidate?->full_name],
                ['Email', $candidate?->email],
                ['Phone', $candidate?->phone],
                ['Where they are', trim(($candidate?->city ? $candidate->city . ', ' : '') . $candidate?->country, ', ')],
                ['Their time zone', $candidate?->timezone],
                ['Expected pay', $application->expectedSalaryLine()],
                ['Can start', $application->availability_start?->format('j M Y') ?: $application->notice_period],
                ['Free during the stated hours', $application->available_during_hours === null ? null : ($application->available_during_hours ? 'Yes' : 'No')],
                ['Has a suitable computer', $application->has_suitable_computer === null ? null : ($application->has_suitable_computer ? 'Yes' : 'No')],
                ['Internet', $application->internet_reliability],
                ['If the power or line goes', $application->backup_arrangements],
                ['Applied', $application->applied_at?->timezone($zone)->format('j M Y, H:i')],
            ] as [$label, $value])
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="mt-0.5 text-gray-950 dark:text-white">{{ $fact($value) }}</dd>
                </div>
            @endforeach
        </dl>

        {{-- Links are printed, never fetched and never embedded. Nothing on
             this server opens a candidate's URL: that would be an SSRF hole
             and would also tell a third party when we read it. --}}
        @if (filled($application->linkedin_url) || filled($application->portfolio_url) || filled($application->work_sample_url))
            <div class="mt-4 border-t border-gray-200 pt-4 text-sm dark:border-gray-700">
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Links they gave</p>
                <ul class="mt-1 space-y-1">
                    @foreach ([
                        'LinkedIn'   => $application->linkedin_url,
                        'Portfolio'  => $application->portfolio_url,
                        'Work sample' => $application->work_sample_url,
                    ] as $label => $url)
                        @if (filled($url))
                            <li>
                                <span class="text-gray-500 dark:text-gray-400">{{ $label }}:</span>
                                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer nofollow"
                                   class="break-all text-primary-600 hover:underline dark:text-primary-400">{{ $url }}</a>
                            </li>
                        @endif
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-4 border-t border-gray-200 pt-4 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
            Privacy notice {{ $fact($application->privacy_version) }},
            accepted
            {{ $application->privacy_accepted_at?->timezone($zone)->format('j M Y, H:i') ?? 'not recorded' }}.
            Future opportunities:
            {{ $candidate?->future_opportunities_consent ? 'they opted in' : 'they did not opt in' }}.
        </div>
    </x-filament::section>

    {{-- What they wrote. Escaped and line-broken, never rendered as HTML:
         this is text a stranger typed into a public form. --}}
    <x-filament::section collapsible>
        <x-slot name="heading">What they wrote</x-slot>

        <div class="space-y-5 text-sm">
            @foreach ([
                'Why they applied'    => $application->motivation,
                'Relevant experience' => $application->relevant_experience,
                'Working remotely'    => $application->remote_experience,
            ] as $label => $body)
                @if (filled($body))
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 whitespace-pre-line leading-relaxed text-gray-950 dark:text-white">{{ $body }}</p>
                    </div>
                @endif
            @endforeach

            @forelse ($application->answers as $answer)
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ $answer->question_label }}
                    </p>
                    <p class="mt-1 whitespace-pre-line leading-relaxed text-gray-950 dark:text-white">
                        {{ $fact($answer->answer) }}
                    </p>
                </div>
            @empty
                <p class="text-gray-500 dark:text-gray-400">
                    This vacancy asked no screening questions.
                </p>
            @endforelse
        </div>
    </x-filament::section>

    {{-- Files. $documents is already everything this reviewer may read; the
         line underneath explains an absence rather than leaving one. --}}
    <x-filament::section>
        <x-slot name="heading">Files</x-slot>
        <x-slot name="description">
            Stored on the private disk. Every download is authorized per file and written to the audit log.
        </x-slot>

        @forelse ($documents as $document)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 py-3 first:pt-0 last:border-0 last:pb-0 dark:border-gray-700">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                        {{ $document->original_name }}
                    </p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ $document->kindLabel() }} · {{ $document->sizeForHumans() }} ·
                        uploaded {{ $document->created_at?->timezone($zone)->format('j M Y') }} ·
                        {{ $document->scanLabel() }}
                        @if (filled($document->verification_status))
                            · {{ $document->verificationLabel() }}
                        @endif
                    </p>
                </div>

                @if ($document->exists())
                    <a href="{{ route('admin.recruitment.document', $document) }}"
                       class="shrink-0 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                        Download
                    </a>
                @else
                    <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">File no longer stored</span>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">No files you can open.</p>
        @endforelse

        @if (! $canSensitive && $hiddenDocuments > 0)
            <p class="mt-4 border-t border-gray-200 pt-4 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                {{ $hiddenDocuments }} identity or guarantor
                {{ \Illuminate\Support\Str::plural('file', $hiddenDocuments) }}
                {{ $hiddenDocuments === 1 ? 'is' : 'are' }} attached and not shown here.
                Those are restricted to HR administrators.
            </p>
        @endif
    </x-filament::section>

    {{-- The exercise. Tasks come off the ASSIGNMENT's snapshot, not off the
         template, so what is shown here is the exercise this person was
         actually set — editing the template afterwards cannot rewrite it. --}}
    @foreach ($application->assignments as $item)
        @php
            $answers = $item->isSubmitted() ? $item->finalAnswers() : $item->draftAnswers();
        @endphp

        <x-filament::section :collapsible="! $loop->first" :collapsed="! $loop->first">
            <x-slot name="heading">
                Assessment — {{ $item->statusLabel() }}
            </x-slot>
            <x-slot name="description">
                Template v{{ $item->template_version }} ·
                invited {{ $item->invited_at?->timezone($zone)->format('j M Y, H:i') ?? '—' }} ·
                due {{ $item->dueForHumans() ?? 'no deadline' }}
                @if ($item->isSubmitted())
                    · submitted {{ $item->submitted_at?->timezone($zone)->format('j M Y, H:i') }}
                @endif
                @if ($item->effortLine())
                    · {{ $item->effortLine() }} of work
                @endif
            </x-slot>

            @if ($item->wasExtended() || $item->reopened_at || $item->cancelled_at)
                <div class="mb-4 rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                    @if ($item->wasExtended())
                        <p>
                            Extended {{ $item->extension_count }}
                            {{ \Illuminate\Support\Str::plural('time', $item->extension_count) }}
                            from {{ $item->original_due_at?->timezone($zone)->format('j M Y, H:i') }}.
                        </p>
                    @endif
                    @if ($item->reopened_at)
                        <p>
                            Reopened {{ $item->reopened_at->timezone($zone)->format('j M Y, H:i') }}
                            @if ($item->reopen_reason) — {{ $item->reopen_reason }} @endif
                        </p>
                    @endif
                    @if ($item->cancelled_at)
                        <p>Cancelled {{ $item->cancelled_at->timezone($zone)->format('j M Y, H:i') }}.</p>
                    @endif
                </div>
            @endif

            <div class="space-y-5 text-sm">
                @forelse ($item->tasks() as $index => $task)
                    @php
                        $key      = (string) ($task['key'] ?? 't' . ($index + 1));
                        $response = $answers[$key] ?? null;
                    @endphp

                    <div>
                        <p class="font-medium text-gray-950 dark:text-white">
                            {{ $index + 1 }}. {{ $task['label'] ?? $key }}
                        </p>
                        @if (filled($task['help'] ?? ''))
                            <p class="mt-0.5 whitespace-pre-line text-xs text-gray-500 dark:text-gray-400">{{ $task['help'] }}</p>
                        @endif
                        <div class="mt-2 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                            @if ($response && filled($response->answer))
                                <p class="whitespace-pre-line leading-relaxed text-gray-950 dark:text-white">{{ $response->answer }}</p>
                                @if (! $response->is_final)
                                    <p class="mt-2 text-xs text-warning-600 dark:text-warning-400">
                                        Draft — not submitted.
                                    </p>
                                @endif
                            @else
                                <p class="text-gray-500 dark:text-gray-400">Nothing written yet.</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-gray-500 dark:text-gray-400">This assignment carries no tasks.</p>
                @endforelse

                @if (filled($item->intro_video_url) || filled($item->intro_alternative))
                    <div class="border-t border-gray-200 pt-4 dark:border-gray-700">
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Introduction</p>
                        @if (filled($item->intro_video_url))
                            {{-- Printed, not embedded. No third-party player is
                                 loaded into this panel and nothing fetches the
                                 address on the candidate's behalf. --}}
                            <a href="{{ $item->intro_video_url }}" target="_blank" rel="noopener noreferrer nofollow"
                               class="mt-1 block break-all text-primary-600 hover:underline dark:text-primary-400">
                                {{ $item->intro_video_url }}
                            </a>
                        @endif
                        @if (filled($item->intro_alternative))
                            <p class="mt-2 whitespace-pre-line leading-relaxed text-gray-950 dark:text-white">{{ $item->intro_alternative }}</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Written instead of a video. That is an equal option and is not marked down.
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        </x-filament::section>
    @endforeach

    {{-- Marks. A criterion nobody has filled in shows as a gap, not a zero,
         and no total is printed anywhere: there is no threshold in this module
         and nothing here ranks one candidate against another. --}}
    <x-filament::section>
        <x-slot name="heading">Reviewer marks</x-slot>
        <x-slot name="description">
            Each reviewer's own marks, side by side. Blank means not yet read, which is not a low score.
        </x-slot>

        @php
            $byReviewer = $application->scores->groupBy('user_id');
        @endphp

        @forelse ($byReviewer as $reviewerScores)
            @php
                $reviewer = $reviewerScores->first()->reviewer;
                $review   = $application->reviews->firstWhere('user_id', $reviewerScores->first()->user_id);
            @endphp

            <div class="border-b border-gray-200 py-4 first:pt-0 last:border-0 last:pb-0 dark:border-gray-700">
                <p class="text-sm font-medium text-gray-950 dark:text-white">
                    {{ $reviewer?->full_name ?? 'A reviewer who has since been removed' }}
                </p>

                <ul class="mt-2 space-y-1.5 text-sm">
                    @foreach ($reviewerScores as $score)
                        <li class="flex items-baseline justify-between gap-4">
                            <span class="text-gray-600 dark:text-gray-300">{{ $score->criterion_label }}</span>
                            <span class="shrink-0 font-medium tabular-nums {{ $score->score === null ? 'text-gray-400 dark:text-gray-500' : 'text-gray-950 dark:text-white' }}">
                                @if ($score->score === null)
                                    not marked
                                @else
                                    {{ $score->score }} / {{ $score->scale_max ?: $scale }}
                                @endif
                            </span>
                        </li>
                        @if (filled($score->comment))
                            <li class="whitespace-pre-line pl-0 text-xs text-gray-500 dark:text-gray-400">{{ $score->comment }}</li>
                        @endif
                    @endforeach
                </ul>

                @if ($review)
                    <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                        <p class="font-medium text-gray-950 dark:text-white">
                            {{ $review->recommendationLabel() }}
                        </p>
                        @if (filled($review->summary))
                            <p class="mt-1 whitespace-pre-line text-gray-600 dark:text-gray-300">{{ $review->summary }}</p>
                        @endif
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $review->submitted_at?->timezone($zone)->format('j M Y, H:i') }}
                        </p>
                    </div>
                @else
                    <p class="mt-2 text-xs text-warning-600 dark:text-warning-400">
                        Marks entered, no recommendation given yet.
                    </p>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nobody has marked this yet.
            </p>
        @endforelse

        @if ($application->vacancy && $application->vacancy->reviewers->isNotEmpty())
            <p class="mt-4 border-t border-gray-200 pt-4 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                Assigned to {{ $application->vacancy->reviewers->pluck('full_name')->join(', ', ' and ') }}.
            </p>
        @else
            <p class="mt-4 border-t border-gray-200 pt-4 text-xs text-warning-600 dark:border-gray-700 dark:text-warning-400">
                No reviewers are assigned to this vacancy, so nobody has been asked to mark it.
            </p>
        @endif
    </x-filament::section>

    {{-- Interviews. The feedback is internal and stays on this screen. --}}
    @if ($application->interviews->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Interviews</x-slot>

            @foreach ($application->interviews->sortByDesc('scheduled_at') as $interview)
                <div class="border-b border-gray-200 py-4 first:pt-0 last:border-0 last:pb-0 dark:border-gray-700">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-medium text-gray-950 dark:text-white">
                            Round {{ $interview->round }} — {{ $interview->timeForHumans() }}
                        </p>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $interview->statusLabel() }}</span>
                    </div>

                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ $interview->duration_minutes }} minutes ·
                        {{ $interview->interviewer?->full_name ?? 'no interviewer named' }} ·
                        {{ $interview->candidate_acknowledged_at
                            ? 'they confirmed ' . $interview->candidate_acknowledged_at->timezone($zone)->format('j M, H:i')
                            : 'not yet confirmed by them' }}
                    </p>

                    @if (filled($interview->meeting_url))
                        <a href="{{ $interview->meeting_url }}" target="_blank" rel="noopener noreferrer"
                           class="mt-1 block break-all text-sm text-primary-600 hover:underline dark:text-primary-400">
                            {{ $interview->meeting_url }}
                        </a>
                    @endif

                    @if ($interview->reschedule_requested_at)
                        <p class="mt-2 rounded-lg bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                            They asked to move this on
                            {{ $interview->reschedule_requested_at->timezone($zone)->format('j M Y, H:i') }}.
                            @if (filled($interview->reschedule_request_note))
                                <span class="block whitespace-pre-line">{{ $interview->reschedule_request_note }}</span>
                            @endif
                        </p>
                    @endif

                    @if (filled($interview->feedback) || filled($interview->outcome))
                        <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Internal notes — never shown to the candidate
                            </p>
                            @if (filled($interview->outcome))
                                <p class="mt-1 font-medium text-gray-950 dark:text-white">
                                    {{ \App\Models\Interview::OUTCOMES[$interview->outcome] ?? $interview->outcome }}
                                </p>
                            @endif
                            @foreach ([
                                'Feedback'        => $interview->feedback,
                                'Pay discussed'   => $interview->salary_discussion_note,
                                'Decision notes'  => $interview->decision_note,
                            ] as $label => $body)
                                @if (filled($body))
                                    <p class="mt-2 whitespace-pre-line text-gray-600 dark:text-gray-300">
                                        <span class="text-gray-500 dark:text-gray-400">{{ $label }}:</span> {{ $body }}
                                    </p>
                                @endif
                            @endforeach
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ $interview->feedback_at?->timezone($zone)->format('j M Y, H:i') }}
                            </p>
                        </div>
                    @endif
                </div>
            @endforeach
        </x-filament::section>
    @endif

    {{-- The offer. The terms shown here are the ones somebody typed or the
         document somebody uploaded: nothing in this module writes employment
         terms, and an acceptance recorded below is a record of a reply, not a
         certified signature. --}}
    @if ($offer)
        <x-filament::section>
            <x-slot name="heading">Offer — {{ $offer->statusLabel() }}</x-slot>
            <x-slot name="description">
                @if ($offer->approved_at)
                    Approved by {{ $offer->approver?->full_name ?? 'an administrator' }} on
                    {{ $offer->approved_at->timezone($zone)->format('j M Y') }}.
                @else
                    Not approved yet, so it cannot be sent.
                @endif
            </x-slot>

            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['Pay', $offer->salaryLine()],
                    ['Arrangement', $offer->compensation_type],
                    ['Start date', $offer->start_date?->format('j M Y')],
                    ['Sent', $offer->sent_at?->timezone($zone)->format('j M Y, H:i')],
                    ['Replied', $offer->responded_at?->timezone($zone)->format('j M Y, H:i')],
                ] as [$label, $value])
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="mt-0.5 text-gray-950 dark:text-white">{{ $fact($value) }}</dd>
                    </div>
                @endforeach
            </dl>

            @if (filled($offer->compensation_note))
                <p class="mt-3 whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $offer->compensation_note }}</p>
            @endif

            @if (filled($offer->body))
                <div class="mt-4 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                    <p class="whitespace-pre-line leading-relaxed text-gray-950 dark:text-white">{{ $offer->body }}</p>
                </div>
            @endif

            @if ($offer->document)
                <p class="mt-3 text-sm">
                    <a href="{{ route('admin.recruitment.document', $offer->document) }}"
                       class="text-primary-600 hover:underline dark:text-primary-400">
                        {{ $offer->document->original_name }}
                    </a>
                    <span class="text-xs text-gray-500 dark:text-gray-400">— the offer document</span>
                </p>
            @endif

            @if (filled($offer->response_note) || filled($offer->decline_reason))
                <p class="mt-3 whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">
                    {{ $offer->response_note ?: $offer->decline_reason }}
                </p>
            @endif
        </x-filament::section>
    @endif

    {{-- Pre-employment checks. A sensitive item is listed by name and status
         for everybody who may review this application, and its FILE only for
         somebody holding sensitive.view — so a coordinator can see that
         identity paperwork is outstanding without being able to open it. --}}
    @if ($application->onboardingItems->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Pre-employment checks</x-slot>
            <x-slot name="description">
                Uploading is not the same as checking. Each item carries who recorded its outcome, and when.
            </x-slot>

            @foreach ($application->onboardingItems as $item)
                <div class="border-b border-gray-200 py-3 first:pt-0 last:border-0 last:pb-0 dark:border-gray-700">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-medium text-gray-950 dark:text-white">
                            {{ $item->label }}
                            @if (! $item->is_required)
                                <span class="text-xs font-normal text-gray-500 dark:text-gray-400">(optional)</span>
                            @endif
                            @if ($item->is_sensitive)
                                <span class="text-xs font-normal text-gray-500 dark:text-gray-400">· restricted</span>
                            @endif
                        </p>
                        <span class="text-xs {{ $item->isOutstanding() ? 'text-warning-600 dark:text-warning-400' : 'text-gray-500 dark:text-gray-400' }}">
                            {{ $item->statusLabel() }}
                        </span>
                    </div>

                    @if (filled($item->instructions))
                        <p class="mt-1 whitespace-pre-line text-xs text-gray-500 dark:text-gray-400">{{ $item->instructions }}</p>
                    @endif

                    @if (filled($item->note))
                        <p class="mt-1 whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $item->note }}</p>
                    @endif

                    <div class="mt-2 flex flex-wrap items-center gap-3 text-xs">
                        @if ($item->verified_at)
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $item->statusLabel() }} by {{ $item->verifier?->full_name ?? 'a member of staff' }}
                                on {{ $item->verified_at->timezone($zone)->format('j M Y') }}
                            </span>
                        @endif

                        @if ($item->document && $canSensitive)
                            <a href="{{ route('admin.recruitment.document', $item->document) }}"
                               class="text-primary-600 hover:underline dark:text-primary-400">
                                Open the file
                            </a>
                        @elseif ($item->document)
                            <span class="text-gray-500 dark:text-gray-400">A file was sent; opening it needs HR access.</span>
                        @endif

                        @if ($canSensitive)
                            {{ ($this->verifyItemAction)(['item' => $item->id]) }}
                        @endif
                    </div>
                </div>
            @endforeach
        </x-filament::section>
    @endif

    {{-- References and guarantors. A guarantor's details belong to a third
         party who agreed to be named, so the whole block is behind
         sensitive.view — and nothing on this screen contacts anybody. That is
         a separate, deliberate act by a member of staff. --}}
    @if ($application->referenceContacts->isNotEmpty())
        @if ($canSensitive)
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">References and guarantors</x-slot>
                <x-slot name="description">
                    Nothing here has been contacted automatically. Consent is recorded per person.
                </x-slot>

                @foreach ($application->referenceContacts as $contact)
                    <div class="border-b border-gray-200 py-3 text-sm first:pt-0 last:border-0 last:pb-0 dark:border-gray-700">
                        <p class="font-medium text-gray-950 dark:text-white">
                            {{ $contact->name }}
                            <span class="text-xs font-normal text-gray-500 dark:text-gray-400">· {{ $contact->typeLabel() }}</span>
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ collect([$contact->relationship, $contact->organisation, $contact->email, $contact->phone])
                                ->filter()->join(' · ') }}
                        </p>
                        <p class="mt-1 text-xs {{ $contact->consent_confirmed ? 'text-gray-500 dark:text-gray-400' : 'text-danger-600 dark:text-danger-400' }}">
                            {{ $contact->consent_confirmed
                                ? 'The candidate confirmed this person agreed to be contacted.'
                                : 'No confirmation that this person agreed to be contacted — do not contact them.' }}
                        </p>
                        @if ($contact->contacted_at)
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Contacted {{ $contact->contacted_at->timezone($zone)->format('j M Y') }}
                                by {{ $contact->contactedBy?->full_name ?? 'a member of staff' }}.
                            </p>
                        @endif
                    </div>
                @endforeach
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">References and guarantors</x-slot>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $application->referenceContacts->count() }} recorded. These are third parties' contact
                    details and are restricted to HR administrators.
                </p>
            </x-filament::section>
        @endif
    @endif

    {{-- Private notes. --}}
    <x-filament::section collapsible>
        <x-slot name="heading">Notes for your own team</x-slot>
        <x-slot name="description">Internal. Never sent anywhere and never shown to the candidate.</x-slot>

        @forelse ($application->notes->sortByDesc('id') as $note)
            <div class="border-b border-gray-200 py-3 text-sm first:pt-0 last:border-0 last:pb-0 dark:border-gray-700">
                <p class="whitespace-pre-line leading-relaxed text-gray-950 dark:text-white">{{ $note->body }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $note->author?->full_name ?? 'Somebody no longer on the team' }} ·
                    {{ $note->created_at?->timezone($zone)->format('j M Y, H:i') }}
                </p>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">No notes yet.</p>
        @endforelse
    </x-filament::section>

    {{-- Email. "Sent" means the mail server took it; only a confirmed provider
         event ever reads as delivered, and nothing here invents one. --}}
    <x-filament::section collapsible>
        <x-slot name="heading">Emails to this candidate</x-slot>
        <x-slot name="description">
            Queued through the outbox, so a message exists as a row before anything is sent and cannot go twice.
        </x-slot>

        @forelse ($application->messages->sortByDesc('id') as $message)
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 py-3 first:pt-0 last:border-0 last:pb-0 dark:border-gray-700">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">
                        {{ \App\Support\RecruitmentTemplates::KEYS[$message->template_key] ?? $message->template_key ?? 'Email' }}
                    </p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ $message->deliveryLabel() }}
                        @if ($message->sent_at)
                            · {{ $message->sent_at->timezone($zone)->format('j M Y, H:i') }}
                        @elseif ($message->available_at)
                            · due {{ $message->available_at->timezone($zone)->format('j M Y, H:i') }}
                        @endif
                        @if ($message->attempts > 0)
                            · {{ $message->attempts }} {{ \Illuminate\Support\Str::plural('attempt', $message->attempts) }}
                        @endif
                    </p>
                    @if (filled($message->cancelled_reason))
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            Called off: {{ $message->cancelled_reason }}
                        </p>
                    @endif
                </div>

                @if ($message->isRetryable() && $message->status === \App\Models\RecruitmentMessage::FAILED)
                    <div class="shrink-0">
                        {{ ($this->retryMessageAction)(['message' => $message->id]) }}
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing has been sent to this candidate yet.</p>
        @endforelse
    </x-filament::section>

    {{-- The record of what was done, who did it and why. Written by the stage
         service on every move, and never edited afterwards. --}}
    <x-filament::section collapsible collapsed>
        <x-slot name="heading">History</x-slot>

        <ol class="space-y-3 text-sm">
            @forelse ($application->stageEvents->sortByDesc('id') as $event)
                <li class="border-l-2 border-gray-200 pl-3 dark:border-gray-700">
                    <p class="text-gray-950 dark:text-white">
                        {{ $event->from_stage
                            ? (\App\Enums\ApplicationStage::tryFrom($event->from_stage)?->label() ?? $event->from_stage) . ' → '
                            : '' }}
                        <span class="font-medium">
                            {{ \App\Enums\ApplicationStage::tryFrom($event->to_stage)?->label() ?? $event->to_stage }}
                        </span>
                    </p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ $event->actor?->full_name ?? $event->actorLabel() }} ·
                        {{ $event->created_at?->timezone($zone)->format('j M Y, H:i') }}
                    </p>
                    @if (filled($event->reason))
                        <p class="mt-1 whitespace-pre-line text-gray-600 dark:text-gray-300">{{ $event->reason }}</p>
                    @endif
                </li>
            @empty
                <li class="text-gray-500 dark:text-gray-400">No stage changes recorded.</li>
            @endforelse
        </ol>
    </x-filament::section>
</x-filament-panels::page>
