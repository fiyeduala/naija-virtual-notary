@extends('layouts.public', [
    'title' => $vacancy->title . ' — Careers | Naija Virtual Notary',
    'description' => \Illuminate\Support\Str::limit(strip_tags($vacancy->summary ?: $vacancy->title . ' at Naija Virtual Notary. Fully remote.'), 150),
])

{{--
    One opening, in full.

    Everything on this page is a fact somebody typed into the panel. There is no
    default salary, no assumed employment type and no invented commission rate:
    a field nobody filled in simply does not render, which is why there are so
    many @if guards here.

    The structured data at the bottom is emitted only when every field Google
    requires for a JobPosting is actually present. A partial one earns a warning
    in Search Console and, worse, publishes a half-fact about a real job.
--}}

@push('styles')
<style>
    .role-head {
        background: linear-gradient(135deg, #0f1a0b 0%, #1a3011 60%, #2a5020 100%);
        color: #fff; padding: 64px 24px 56px;
    }
    .role-head .container { max-width: 880px; }
    .role-head .dept {
        font-size: 13px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;
        color: rgba(255,255,255,.65); margin-bottom: 12px;
    }
    .role-head h1 { font-size: clamp(28px, 4vw, 44px); font-weight: 800; color: #fff; line-height: 1.2; margin-bottom: 20px; }
    .head-tags { display: flex; flex-wrap: wrap; gap: 9px; margin-bottom: 26px; }
    .head-tags span {
        font-size: 13px; font-weight: 500; padding: 6px 13px; border-radius: 999px;
        background: rgba(255,255,255,.12); color: rgba(255,255,255,.9);
    }
    .head-tags .is-remote { background: var(--brand); color: #fff; font-weight: 600; }

    .closed-banner {
        background: #fff4e5; border: 1px solid #f0c589; color: #7a4b00;
        padding: 16px 20px; border-radius: var(--radius); font-size: 15px; line-height: 1.6;
        margin-bottom: 28px;
    }
    .preview-banner {
        background: #e9f2ff; border: 1px solid #a8c7f0; color: #14407a;
        padding: 16px 20px; border-radius: var(--radius); font-size: 14.5px; line-height: 1.6;
        margin-bottom: 28px;
    }

    .role-body { max-width: 880px; margin: 0 auto; }
    .role-grid { display: grid; grid-template-columns: 1fr 290px; gap: 44px; align-items: start; }
    @media (max-width: 860px) { .role-grid { grid-template-columns: 1fr; gap: 32px; } }

    .prose h2 { font-size: 22px; font-weight: 700; margin: 36px 0 14px; }
    .prose h2:first-child { margin-top: 0; }
    .prose p, .prose li { font-size: 16px; line-height: 1.8; color: #3d4a57; }
    .prose p { margin-bottom: 14px; }
    .prose ul { margin: 0 0 16px 22px; }
    .prose li { margin-bottom: 8px; }

    .facts {
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
        padding: 24px; position: sticky; top: 24px;
    }
    .facts dt {
        font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
        color: var(--muted); margin-bottom: 5px;
    }
    .facts dd { font-size: 15px; color: var(--ink); margin: 0 0 18px; line-height: 1.6; }
    .facts dd:last-child { margin-bottom: 0; }
    .facts .btn { width: 100%; justify-content: center; margin-top: 8px; }

    .stages { counter-reset: stage; list-style: none; margin: 0; }
    .stages li {
        counter-increment: stage; position: relative; padding-left: 38px;
        margin-bottom: 14px; font-size: 15.5px; line-height: 1.65; color: #3d4a57;
    }
    .stages li::before {
        content: counter(stage); position: absolute; left: 0; top: 0;
        width: 26px; height: 26px; border-radius: 50%; background: var(--brand-light);
        color: var(--brand-dark); font-size: 13px; font-weight: 700;
        display: flex; align-items: center; justify-content: center;
    }

    .fineprint {
        margin-top: 40px; padding: 22px 26px; background: var(--surface);
        border: 1px solid var(--line); border-left: 3px solid var(--brand);
        border-radius: var(--radius); font-size: 14.5px; color: var(--muted); line-height: 1.7;
    }
    .fineprint strong { color: var(--ink); }
    .fineprint a { color: var(--brand-dark); font-weight: 600; }
</style>
@endpush

@section('content')

@php
    $zone   = config('recruitment.timezone');
    $open   = $vacancy->acceptsApplications();
    $effort = (array) config('recruitment.assessment.effort_minutes', [30, 45]);
@endphp

<header class="role-head">
    <div class="container">
        @if ($vacancy->departmentLabel())
            <div class="dept">{{ $vacancy->departmentLabel() }}</div>
        @endif

        <h1>{{ $vacancy->title }}</h1>

        <div class="head-tags">
            <span class="is-remote">Fully remote</span>
            @if (filled($vacancy->employment_type))<span>{{ $vacancy->employment_type }}</span>@endif
            @if (filled($vacancy->location_eligibility))<span>{{ $vacancy->location_eligibility }}</span>@endif
            @if ($vacancy->salaryLine())<span>{{ $vacancy->salaryLine() }}</span>@endif
        </div>

        @if ($open)
            <a href="{{ route('careers.apply', $vacancy->slug) }}" class="btn btn-white btn-lg">Apply for this role</a>
        @endif
    </div>
</header>

<section class="section">
    <div class="role-body">

        @if ($preview ?? false)
            <div class="preview-banner">
                <strong>You are previewing this advert as an administrator.</strong>
                It is {{ $vacancy->state->label() ?? $vacancy->state->value }} and is not on the public
                list. Nobody outside the panel can open this page yet.
            </div>
        @endif

        @unless ($open)
            <div class="closed-banner">{{ $vacancy->closureReason() }}</div>
        @endunless

        <div class="role-grid">
            <div class="prose">

                @if (filled($vacancy->summary))
                    <h2>About the role</h2>
                    {!! nl2br(e($vacancy->summary)) !!}
                @endif

                @if (filled($vacancy->responsibilities))
                    <h2>What you will be doing</h2>
                    {!! nl2br(e($vacancy->responsibilities)) !!}
                @endif

                @if (filled($vacancy->requirements))
                    <h2>What we are looking for</h2>
                    {!! nl2br(e($vacancy->requirements)) !!}
                @endif

                @if (filled($vacancy->remote_arrangements))
                    <h2>How remote working works here</h2>
                    {!! nl2br(e($vacancy->remote_arrangements)) !!}
                @endif

                @if ($vacancy->compensationLine())
                    <h2>Pay</h2>
                    <p>{{ $vacancy->compensationLine() }}</p>
                    @if ($vacancy->salaryLine())
                        <p><strong>{{ $vacancy->salaryLine() }}</strong></p>
                    @endif
                @endif

                <h2>How we hire</h2>
                <ol class="stages">
                    <li>You apply using the form on this site. We confirm it by email with a reference.</li>
                    <li>We read your application. If we would like to go further, we invite you to a short
                        written exercise for this role.</li>
                    <li>The exercise is about
                        {{ (int) ($effort[0] ?? 30) }}–{{ (int) ($effort[1] ?? 45) }} minutes of work, and you
                        get {{ $vacancy->assessmentDueWorkingDays() }} working
                        {{ \Illuminate\Support\Str::plural('day', $vacancy->assessmentDueWorkingDays()) }}
                        from the invitation to send it back.</li>
                    <li>A person reads it — every exercise here is read and scored by a member of our team.</li>
                    <li>An interview by video call, at a time we agree with you.</li>
                    <li>An offer, and a short list of things we need before you start.</li>
                </ol>

                @if (filled($vacancy->apply_instructions))
                    <h2>Before you apply</h2>
                    {!! nl2br(e($vacancy->apply_instructions)) !!}
                @endif
            </div>

            <aside>
                <dl class="facts">
                    <dt>Location</dt>
                    <dd>
                        Remote.
                        @if (filled($vacancy->location_eligibility))
                            You must be able to work from {{ $vacancy->location_eligibility }}.
                        @endif
                    </dd>

                    @if (filled($vacancy->working_hours))
                        <dt>Hours you need to be available</dt>
                        <dd>
                            {{ $vacancy->working_hours }}
                            @if (filled($vacancy->hours_timezone))
                                <br><span style="color: var(--muted); font-size: 14px;">{{ $vacancy->hours_timezone }}</span>
                            @endif
                        </dd>
                    @endif

                    @if (filled($vacancy->employment_type))
                        <dt>Employment type</dt>
                        <dd>{{ $vacancy->employment_type }}</dd>
                    @endif

                    <dt>Pay</dt>
                    <dd>
                        @if ($vacancy->salaryLine())
                            {{ $vacancy->salaryLine() }}
                        @else
                            Discussed at interview.
                        @endif
                    </dd>

                    @if ($vacancy->closes_at)
                        <dt>Applications close</dt>
                        <dd>{{ $vacancy->closes_at->timezone($zone)->format('j F Y') }}</dd>
                    @endif

                    @if ($open)
                        <a href="{{ route('careers.apply', $vacancy->slug) }}" class="btn btn-primary">Apply now</a>
                    @else
                        <a href="{{ route('careers.index') }}" class="btn btn-outline" style="width:100%;justify-content:center;">See other openings</a>
                    @endif
                </dl>
            </aside>
        </div>

        <div class="fineprint">
            <strong>There is no fee to apply for this role</strong>, at any stage. We will never ask you for
            money, for your bank details, or for a payment to be considered — and we do not ask for
            identity documents until there is an offer on the table. What we do with the information you
            send us is set out in our <a href="{{ route('careers.privacy') }}">recruitment privacy notice</a>.
        </div>

    </div>
</section>

{{--
    JobPosting structured data — all or nothing.

    Google requires title, description, datePosted, hiringOrganization and,
    for a remote role, jobLocationType plus applicantLocationRequirements.
    If any one of them is missing this block is skipped entirely rather than
    emitted with a guess in it: an invented location on a real job advert is
    worse than no rich result at all.

    Salary appears only where the office chose to disclose it, and only with
    the currency and period they actually set.
--}}
@php
    $complete = $vacancy->state === \App\Enums\VacancyState::Published
        && filled($vacancy->summary)
        && filled($vacancy->location_eligibility)
        && $vacancy->published_at !== null;

    $schema = null;

    if ($complete) {
        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'JobPosting',
            'title'       => $vacancy->title,
            'description' => trim(implode("\n\n", array_filter([
                $vacancy->summary,
                $vacancy->responsibilities,
                $vacancy->requirements,
            ]))),
            'datePosted'  => $vacancy->published_at->toAtomString(),
            'hiringOrganization' => [
                '@type' => 'Organization',
                'name'  => 'Naija Virtual Notary',
                'sameAs' => rtrim(config('app.url'), '/'),
            ],
            'jobLocationType' => 'TELECOMMUTE',
            'applicantLocationRequirements' => [
                '@type' => 'Country',
                'name'  => $vacancy->location_eligibility,
            ],
            'directApply' => true,
        ];

        if ($vacancy->closes_at) {
            $schema['validThrough'] = $vacancy->closes_at->toAtomString();
        }

        if (filled($vacancy->employment_type)) {
            $schema['employmentType'] = \Illuminate\Support\Str::upper(
                str_replace([' ', '-'], '_', $vacancy->employment_type),
            );
        }

        if ($vacancy->salary_disclosure === \App\Models\Vacancy::SALARY_DISCLOSED
            && filled($vacancy->salary_min_minor)
            && filled($vacancy->salary_currency)
            && filled($vacancy->pay_period)) {
            $schema['baseSalary'] = [
                '@type'    => 'MonetaryAmount',
                'currency' => strtoupper((string) $vacancy->salary_currency),
                'value'    => array_filter([
                    '@type'    => 'QuantitativeValue',
                    'minValue' => ((int) $vacancy->salary_min_minor) / 100,
                    'maxValue' => $vacancy->salary_max_minor ? ((int) $vacancy->salary_max_minor) / 100 : null,
                    'unitText' => match ($vacancy->pay_period) {
                        'hour'  => 'HOUR',
                        'year'  => 'YEAR',
                        default => 'MONTH',
                    },
                ], fn ($value) => $value !== null),
            ];
        }
    }
@endphp

@if ($schema)
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif

@endsection
