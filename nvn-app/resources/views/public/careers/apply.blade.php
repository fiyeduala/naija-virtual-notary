@extends('layouts.public', [
    'title' => 'Apply: ' . $vacancy->title . ' | Naija Virtual Notary',
    'description' => 'Apply for the ' . $vacancy->title . ' role at Naija Virtual Notary.',
])

{{--
    The application form.

    Sectioned, because one long column of thirty fields is how people give up
    halfway. Every label is a real <label for>, every error sits against the
    field it belongs to, and the required marks are not colour alone.

    What this form does not ask for is deliberate: no date of birth, no
    marital status, no religion, no photograph, no identity number, no bank
    details and no guarantor. None of it helps decide whether somebody can do
    the job, and collecting it early means holding it for everyone who was not
    hired.
--}}

@push('styles')
<style>
    .apply-head { background: #0f1a0b; color: #fff; padding: 44px 24px 40px; }
    .apply-head .container { max-width: 760px; }
    .apply-head .back { font-size: 14px; color: rgba(255,255,255,.6); display: inline-block; margin-bottom: 14px; }
    .apply-head .back:hover { color: var(--brand); }
    .apply-head h1 { font-size: clamp(24px, 3vw, 34px); font-weight: 700; color: #fff; }
    .apply-head p { color: rgba(255,255,255,.7); font-size: 15px; margin-top: 10px; line-height: 1.6; }

    .form-wrap { max-width: 760px; margin: 0 auto; }

    .fieldset {
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
        padding: 28px; margin-bottom: 20px; border: 1px solid var(--line);
    }
    .fieldset > legend {
        font-size: 18px; font-weight: 700; color: var(--ink); padding: 0; margin-bottom: 6px;
    }
    .fieldset .legend-note { font-size: 14px; color: var(--muted); line-height: 1.6; margin-bottom: 20px; }

    .field { margin-bottom: 18px; }
    .field:last-child { margin-bottom: 0; }
    .field label { display: block; font-size: 14.5px; font-weight: 600; margin-bottom: 7px; }
    .field .req { color: #b4231c; font-weight: 700; }
    .field .opt { color: var(--muted); font-weight: 400; font-size: 13px; }
    .field .hint { font-size: 13.5px; color: var(--muted); line-height: 1.6; margin-bottom: 8px; }
    .field input[type=text], .field input[type=email], .field input[type=tel],
    .field input[type=url], .field input[type=number], .field input[type=date],
    .field select, .field textarea {
        width: 100%; padding: 11px 13px; font-family: var(--font); font-size: 15px;
        color: var(--ink); background: #fff; border: 1px solid #ccd3da; border-radius: 8px;
    }
    .field textarea { min-height: 128px; resize: vertical; line-height: 1.6; }
    .field input:focus, .field select:focus, .field textarea:focus {
        outline: 2px solid var(--brand); outline-offset: 1px; border-color: var(--brand);
    }
    .field input[type=file] {
        width: 100%; padding: 11px; font-size: 14px; background: #f7f9fa;
        border: 1px dashed #ccd3da; border-radius: 8px;
    }
    .field .err { display: block; font-size: 13.5px; color: #b4231c; margin-top: 6px; font-weight: 500; }
    .field input[aria-invalid=true], .field textarea[aria-invalid=true], .field select[aria-invalid=true] {
        border-color: #b4231c; background: #fff8f8;
    }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
    @media (max-width: 620px) { .row, .row-3 { grid-template-columns: 1fr; } }

    .check { display: flex; gap: 11px; align-items: flex-start; margin-bottom: 14px; }
    .check:last-child { margin-bottom: 0; }
    .check input { margin-top: 3px; width: 17px; height: 17px; flex-shrink: 0; accent-color: var(--brand); }
    .check label { font-size: 14.5px; font-weight: 400; line-height: 1.6; margin: 0; }
    .check label strong { font-weight: 600; }
    .check a { color: var(--brand-dark); font-weight: 600; text-decoration: underline; }

    .alert-err {
        background: #fff5f5; border: 1px solid #f0b4b0; color: #8a1a14;
        padding: 16px 20px; border-radius: var(--radius); margin-bottom: 22px;
        font-size: 14.5px; line-height: 1.6;
    }
    .alert-err ul { margin: 8px 0 0 20px; }

    .submit-bar { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; }
    .submit-bar .note { font-size: 13.5px; color: var(--muted); line-height: 1.6; flex: 1 1 240px; }
    .submit-bar .btn[disabled] { opacity: .6; cursor: wait; }
</style>
@endpush

@section('content')

<header class="apply-head">
    <div class="container">
        <a href="{{ route('careers.show', $vacancy->slug) }}" class="back">&larr; Back to the role</a>
        <h1>Apply: {{ $vacancy->title }}</h1>
        <p>
            Remote.
            @if (filled($vacancy->location_eligibility)) {{ $vacancy->location_eligibility }}. @endif
            @if ($vacancy->closes_at)
                Closes {{ $vacancy->closes_at->timezone(config('recruitment.timezone'))->format('j F Y') }}.
            @endif
        </p>
    </div>
</header>

<section class="section-sm">
    <div class="form-wrap">

        @if ($errors->any())
            <div class="alert-err" role="alert" tabindex="-1" id="form-errors">
                <strong>There {{ $errors->count() === 1 ? 'is one thing' : 'are a few things' }} to fix before this can be sent:</strong>
                <ul>
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('careers.apply.store', $vacancy->slug) }}"
              enctype="multipart/form-data" id="apply-form" novalidate>
            @csrf

            <fieldset class="fieldset">
                <legend>About you</legend>
                <p class="legend-note">How we reach you, and where you are working from.</p>

                <div class="field">
                    <label for="full_name">Full name <span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="full_name" name="full_name" value="{{ old('full_name') }}"
                           required autocomplete="name"
                           @error('full_name') aria-invalid="true" aria-describedby="full_name-err" @enderror>
                    @error('full_name')<span class="err" id="full_name-err">{{ $message }}</span>@enderror
                </div>

                <div class="row">
                    <div class="field">
                        <label for="email">Email address <span class="req" aria-hidden="true">*</span></label>
                        <p class="hint">Everything about your application goes here, so please check it.</p>
                        <input type="email" id="email" name="email" value="{{ old('email') }}"
                               required autocomplete="email"
                               @error('email') aria-invalid="true" aria-describedby="email-err" @enderror>
                        @error('email')<span class="err" id="email-err">{{ $message }}</span>@enderror
                    </div>

                    <div class="field">
                        <label for="phone">Phone number <span class="req" aria-hidden="true">*</span></label>
                        <input type="tel" id="phone" name="phone" value="{{ old('phone') }}"
                               required autocomplete="tel"
                               @error('phone') aria-invalid="true" aria-describedby="phone-err" @enderror>
                        @error('phone')<span class="err" id="phone-err">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="row-3">
                    <div class="field">
                        <label for="country">Country you work from <span class="req" aria-hidden="true">*</span></label>
                        <input type="text" id="country" name="country" value="{{ old('country') }}"
                               required autocomplete="country-name"
                               @error('country') aria-invalid="true" @enderror>
                        @error('country')<span class="err">{{ $message }}</span>@enderror
                    </div>

                    <div class="field">
                        <label for="city">City <span class="opt">(optional)</span></label>
                        <input type="text" id="city" name="city" value="{{ old('city') }}" autocomplete="address-level2">
                        @error('city')<span class="err">{{ $message }}</span>@enderror
                    </div>

                    <div class="field">
                        <label for="timezone">Your time zone <span class="req" aria-hidden="true">*</span></label>
                        <select id="timezone" name="timezone" required
                                @error('timezone') aria-invalid="true" @enderror>
                            @foreach (\DateTimeZone::listIdentifiers() as $identifier)
                                <option value="{{ $identifier }}"
                                    @selected(old('timezone', config('recruitment.timezone')) === $identifier)>
                                    {{ str_replace('_', ' ', $identifier) }}
                                </option>
                            @endforeach
                        </select>
                        @error('timezone')<span class="err">{{ $message }}</span>@enderror
                    </div>
                </div>
            </fieldset>

            <fieldset class="fieldset">
                <legend>Your CV</legend>
                <p class="legend-note">
                    {{ strtoupper(implode(' or ', (array) config('recruitment.uploads.cv.extensions', ['pdf']))) }}
                    only, up to {{ $maxSize }}. Nothing else is accepted.
                </p>

                <div class="field">
                    <label for="cv">Upload your CV <span class="req" aria-hidden="true">*</span></label>
                    <input type="file" id="cv" name="cv" accept="{{ $accept }}" required
                           @error('cv') aria-invalid="true" aria-describedby="cv-err" @enderror>
                    @error('cv')<span class="err" id="cv-err">{{ $message }}</span>@enderror
                    <p class="hint" id="cv-chosen" style="margin-top:8px;margin-bottom:0;" aria-live="polite"></p>
                </div>
            </fieldset>

            <fieldset class="fieldset">
                <legend>Why this role</legend>
                <p class="legend-note">This is the part a person actually reads. Plain writing is fine.</p>

                <div class="field">
                    <label for="motivation">Why do you want this role? <span class="req" aria-hidden="true">*</span></label>
                    <textarea id="motivation" name="motivation" required maxlength="6000"
                              @error('motivation') aria-invalid="true" @enderror>{{ old('motivation') }}</textarea>
                    @error('motivation')<span class="err">{{ $message }}</span>@enderror
                </div>

                <div class="field">
                    <label for="relevant_experience">What experience do you have that is relevant to it? <span class="req" aria-hidden="true">*</span></label>
                    <textarea id="relevant_experience" name="relevant_experience" required maxlength="6000"
                              @error('relevant_experience') aria-invalid="true" @enderror>{{ old('relevant_experience') }}</textarea>
                    @error('relevant_experience')<span class="err">{{ $message }}</span>@enderror
                </div>
            </fieldset>

            @if ($vacancy->questions() !== [])
                <fieldset class="fieldset">
                    <legend>A few questions about this role</legend>
                    <p class="legend-note">Short answers are fine — these are to understand your approach, not to catch you out.</p>

                    @foreach ($vacancy->questions() as $question)
                        @php $name = 'answers.' . $question['key']; @endphp
                        <div class="field">
                            <label for="q-{{ $question['key'] }}">
                                {{ $question['label'] }}
                                @if ($question['required'])
                                    <span class="req" aria-hidden="true">*</span>
                                @else
                                    <span class="opt">(optional)</span>
                                @endif
                            </label>

                            @if ($question['type'] === 'select' && $question['options'] !== [])
                                <select id="q-{{ $question['key'] }}" name="answers[{{ $question['key'] }}]"
                                        @error($name) aria-invalid="true" @enderror>
                                    <option value="">Please choose</option>
                                    @foreach ($question['options'] as $option)
                                        <option value="{{ $option }}"
                                            @selected(old('answers.' . $question['key']) === $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            @elseif ($question['type'] === 'yes_no')
                                <select id="q-{{ $question['key'] }}" name="answers[{{ $question['key'] }}]"
                                        @error($name) aria-invalid="true" @enderror>
                                    <option value="">Please choose</option>
                                    <option value="Yes" @selected(old('answers.' . $question['key']) === 'Yes')>Yes</option>
                                    <option value="No" @selected(old('answers.' . $question['key']) === 'No')>No</option>
                                </select>
                            @elseif ($question['type'] === 'short_text')
                                <input type="text" id="q-{{ $question['key'] }}" name="answers[{{ $question['key'] }}]"
                                       value="{{ old('answers.' . $question['key']) }}" maxlength="500"
                                       @error($name) aria-invalid="true" @enderror>
                            @elseif ($question['type'] === 'url')
                                <input type="url" id="q-{{ $question['key'] }}" name="answers[{{ $question['key'] }}]"
                                       value="{{ old('answers.' . $question['key']) }}" placeholder="https://"
                                       @error($name) aria-invalid="true" @enderror>
                            @else
                                <textarea id="q-{{ $question['key'] }}" name="answers[{{ $question['key'] }}]"
                                          maxlength="8000"
                                          @error($name) aria-invalid="true" @enderror>{{ old('answers.' . $question['key']) }}</textarea>
                            @endif

                            @error($name)<span class="err">{{ $message }}</span>@enderror
                        </div>
                    @endforeach
                </fieldset>
            @endif

            <fieldset class="fieldset">
                <legend>Links <span class="opt" style="font-size:14px;">— all optional</span></legend>
                <p class="legend-note">
                    If you have work to show us, link it. You do not need a social media account to apply
                    here, and nothing is counted against you for leaving these blank.
                </p>

                <div class="field">
                    <label for="linkedin_url">LinkedIn <span class="opt">(optional)</span></label>
                    <input type="url" id="linkedin_url" name="linkedin_url" value="{{ old('linkedin_url') }}"
                           placeholder="https://" @error('linkedin_url') aria-invalid="true" @enderror>
                    @error('linkedin_url')<span class="err">{{ $message }}</span>@enderror
                </div>

                <div class="field">
                    <label for="portfolio_url">Portfolio or website <span class="opt">(optional)</span></label>
                    <input type="url" id="portfolio_url" name="portfolio_url" value="{{ old('portfolio_url') }}"
                           placeholder="https://" @error('portfolio_url') aria-invalid="true" @enderror>
                    @error('portfolio_url')<span class="err">{{ $message }}</span>@enderror
                </div>

                <div class="field">
                    <label for="work_sample_url">A sample of your work <span class="opt">(optional)</span></label>
                    <input type="url" id="work_sample_url" name="work_sample_url" value="{{ old('work_sample_url') }}"
                           placeholder="https://" @error('work_sample_url') aria-invalid="true" @enderror>
                    @error('work_sample_url')<span class="err">{{ $message }}</span>@enderror
                </div>
            </fieldset>

            <fieldset class="fieldset">
                <legend>Pay and availability</legend>
                <p class="legend-note">
                    Tell us what you are looking for. It is a starting point for a conversation, not a test.
                </p>

                <div class="row-3">
                    <div class="field">
                        <label for="expected_salary_min">Expected pay, from <span class="opt">(optional)</span></label>
                        <input type="number" id="expected_salary_min" name="expected_salary_min" min="0" step="1"
                               value="{{ old('expected_salary_min') }}"
                               @error('expected_salary_min') aria-invalid="true" @enderror>
                        @error('expected_salary_min')<span class="err">{{ $message }}</span>@enderror
                    </div>

                    <div class="field">
                        <label for="expected_salary_max">to <span class="opt">(optional)</span></label>
                        <input type="number" id="expected_salary_max" name="expected_salary_max" min="0" step="1"
                               value="{{ old('expected_salary_max') }}"
                               @error('expected_salary_max') aria-invalid="true" @enderror>
                        @error('expected_salary_max')<span class="err">{{ $message }}</span>@enderror
                    </div>

                    <div class="field">
                        <label for="expected_salary_currency">Currency</label>
                        <select id="expected_salary_currency" name="expected_salary_currency">
                            @foreach (['NGN' => 'Naira (NGN)', 'USD' => 'US dollars (USD)', 'GBP' => 'Pounds (GBP)', 'EUR' => 'Euro (EUR)'] as $code => $label)
                                <option value="{{ $code }}" @selected(old('expected_salary_currency', 'NGN') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="field">
                        <label for="expected_pay_period">Per</label>
                        <select id="expected_pay_period" name="expected_pay_period">
                            @foreach (\App\Models\Vacancy::PAY_PERIODS as $key => $label)
                                <option value="{{ $key }}" @selected(old('expected_pay_period', 'month') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="availability_start">Earliest start date <span class="opt">(optional)</span></label>
                        <input type="date" id="availability_start" name="availability_start"
                               value="{{ old('availability_start') }}" min="{{ now()->toDateString() }}"
                               @error('availability_start') aria-invalid="true" @enderror>
                        @error('availability_start')<span class="err">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="field">
                    <label for="notice_period">Notice period, if you have one <span class="opt">(optional)</span></label>
                    <input type="text" id="notice_period" name="notice_period" value="{{ old('notice_period') }}"
                           maxlength="80" placeholder="e.g. one month">
                    @error('notice_period')<span class="err">{{ $message }}</span>@enderror
                </div>
            </fieldset>

            <fieldset class="fieldset">
                <legend>Working remotely</legend>
                <p class="legend-note">
                    This role is remote, which means we need to know you can actually work it.
                    @if (filled($vacancy->working_hours))
                        The hours are <strong>{{ $vacancy->working_hours }}</strong>@if (filled($vacancy->hours_timezone)) ({{ $vacancy->hours_timezone }})@endif.
                    @endif
                </p>

                <div class="field">
                    <label for="internet_reliability">How reliable is your internet connection? <span class="req" aria-hidden="true">*</span></label>
                    <select id="internet_reliability" name="internet_reliability" required
                            @error('internet_reliability') aria-invalid="true" @enderror>
                        <option value="">Please choose</option>
                        @foreach ([
                            'Very reliable' => 'Very reliable - rarely goes down',
                            'Mostly reliable' => 'Mostly reliable - occasional outages',
                            'Sometimes unreliable' => 'Sometimes unreliable',
                        ] as $value => $label)
                            <option value="{{ $value }}" @selected(old('internet_reliability') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('internet_reliability')<span class="err">{{ $message }}</span>@enderror
                </div>

                <div class="field">
                    <label for="backup_arrangements">What do you do when the power or internet goes? <span class="req" aria-hidden="true">*</span></label>
                    <select id="backup_arrangements" name="backup_arrangements" required
                            @error('backup_arrangements') aria-invalid="true" @enderror>
                        <option value="">Please choose</option>
                        @foreach ([
                            'Inverter or generator' => 'I have an inverter or a generator',
                            'Mobile data backup' => 'I switch to mobile data',
                            'Both' => 'Both power backup and mobile data',
                            'Nearby workspace' => 'I move somewhere with power',
                            'No backup' => 'I have no backup arrangement',
                        ] as $value => $label)
                            <option value="{{ $value }}" @selected(old('backup_arrangements') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('backup_arrangements')<span class="err">{{ $message }}</span>@enderror
                </div>

                <div class="field">
                    <label for="remote_experience">Have you worked remotely before? Tell us about it. <span class="opt">(optional)</span></label>
                    <textarea id="remote_experience" name="remote_experience" maxlength="2000"
                              style="min-height:90px;">{{ old('remote_experience') }}</textarea>
                    @error('remote_experience')<span class="err">{{ $message }}</span>@enderror
                </div>

                <div class="check">
                    <input type="checkbox" id="available_during_hours" name="available_during_hours" value="1"
                           @checked(old('available_during_hours')) required>
                    <label for="available_during_hours">
                        I can be available during the hours stated for this role.
                        <span class="req" aria-hidden="true">*</span>
                    </label>
                </div>
                @error('available_during_hours')<span class="err" style="display:block;margin:-8px 0 14px 28px;">{{ $message }}</span>@enderror

                <div class="check">
                    <input type="checkbox" id="has_suitable_computer" name="has_suitable_computer" value="1"
                           @checked(old('has_suitable_computer')) required>
                    <label for="has_suitable_computer">
                        I have access to a computer suitable for this work.
                        <span class="req" aria-hidden="true">*</span>
                    </label>
                </div>
                @error('has_suitable_computer')<span class="err" style="display:block;margin:-8px 0 0 28px;">{{ $message }}</span>@enderror
            </fieldset>

            <fieldset class="fieldset">
                <legend>Before you send this</legend>
                <p class="legend-note">
                    Two separate things, asked separately on purpose. The first is about this
                    application. The second is about anything after it, and it is entirely up to you.
                </p>

                <div class="check">
                    <input type="checkbox" id="privacy_accepted" name="privacy_accepted" value="1"
                           @checked(old('privacy_accepted')) required>
                    <label for="privacy_accepted">
                        I have read the <a href="{{ route('careers.privacy') }}" target="_blank" rel="noopener noreferrer">recruitment
                        privacy notice</a> and I understand how my information will be used for this application.
                        <span class="req" aria-hidden="true">*</span>
                    </label>
                </div>
                @error('privacy_accepted')<span class="err" style="display:block;margin:-8px 0 14px 28px;">{{ $message }}</span>@enderror

                <div class="check" style="padding-top:12px;border-top:1px solid var(--line);margin-top:16px;">
                    <input type="checkbox" id="future_opportunities_consent" name="future_opportunities_consent" value="1"
                           @checked(old('future_opportunities_consent'))>
                    <label for="future_opportunities_consent">
                        <strong>Optional.</strong> You may keep my details to tell me about other roles here in future.
                        Leaving this unticked has no effect at all on this application.
                    </label>
                </div>

                <div class="field" style="margin-top:20px;">
                    <label for="heard_about">How did you hear about this role? <span class="opt">(optional)</span></label>
                    <select id="heard_about" name="heard_about">
                        <option value="">Prefer not to say</option>
                        @foreach (\App\Support\SourceAttribution::options() as $value => $label)
                            <option value="{{ $value }}" @selected(old('heard_about') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </fieldset>

            <div class="fieldset">
                <div class="submit-bar">
                    <button type="submit" class="btn btn-primary btn-lg" id="apply-submit">
                        Send my application
                    </button>
                    <p class="note">
                        We will email you a reference as soon as this is saved. If you do not hear
                        anything, check your spam folder before sending it again.
                    </p>
                </div>
            </div>
        </form>

    </div>
</section>

<script>
(function () {
    var form = document.getElementById('apply-form');
    var button = document.getElementById('apply-submit');
    var cv = document.getElementById('cv');
    var chosen = document.getElementById('cv-chosen');
    var maxBytes = {{ (int) config('recruitment.uploads.cv.max_kb', 5120) }} * 1024;

    // The size check the browser can do, so somebody on a slow connection is
    // not told their 20 MB CV is too big AFTER uploading all of it. The server
    // checks again regardless — this is a courtesy, not the rule.
    if (cv) {
        cv.addEventListener('change', function () {
            var file = cv.files && cv.files[0];
            if (!file) { chosen.textContent = ''; return; }

            if (file.size > maxBytes) {
                chosen.textContent = 'That file is ' + Math.round(file.size / 1048576 * 10) / 10
                    + ' MB, which is over the {{ $maxSize }} limit. Please choose a smaller one.';
                chosen.style.color = '#b4231c';
                cv.value = '';
                return;
            }

            chosen.style.color = '';
            chosen.textContent = 'Selected: ' + file.name
                + ' (' + Math.max(1, Math.round(file.size / 1024)) + ' KB)';
        });
    }

    // Stops the double-tap that arrives as two POSTs. The intake service is
    // idempotent anyway, so the worst case was always a duplicate request
    // rather than a duplicate application, but the spinner tells the candidate
    // their tap registered.
    if (form) {
        form.addEventListener('submit', function () {
            button.setAttribute('disabled', 'disabled');
            button.textContent = 'Sending your application...';
            window.setTimeout(function () { button.removeAttribute('disabled'); }, 20000);
        });
    }

    var errors = document.getElementById('form-errors');
    if (errors) { errors.focus(); }
})();
</script>

@endsection
