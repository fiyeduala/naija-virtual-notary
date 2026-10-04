@extends('layouts.public', [
    'title' => 'Partner with us — for organizations | Naija Virtual Notary',
    'description' => 'Government bodies, embassies, law firms and companies: send your applicants to us at your own agreed rate, notarized by our own notary public.',
])

{{--
    A body applying to partner.

    The same questions the office asks when it onboards a body by hand, minus
    the money: an applicant does not propose its own rate or its own cut. Those
    stay blank until somebody has had the conversation.

    Contact details are mandatory and asked for separately from the body
    itself, because they are what the office uses when a submission has a
    mistake in it — which is the single most common thing to happen to a form
    like this.
--}}

@push('styles')
<style>
    .page-hero {
        background: linear-gradient(135deg, #0f1a0b, #1a3011 60%, #2a5020);
        color: #fff; padding: 72px 24px 64px; text-align: center;
    }
    .page-hero h1 { font-size: clamp(30px, 4vw, 48px); font-weight: 800; color: #fff; margin-bottom: 16px; }
    .page-hero p { font-size: 17.5px; color: rgba(255,255,255,.82); max-width: 640px; margin: 0 auto; line-height: 1.65; }
    .page-hero .btns { display: flex; gap: 14px; justify-content: center; margin-top: 28px; flex-wrap: wrap; }

    .why-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px; margin-top: 44px; }
    .why-card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 28px 24px; }
    .why-card h3 { font-size: 17px; font-weight: 600; margin-bottom: 9px; }
    .why-card p { font-size: 14px; color: var(--muted); line-height: 1.68; }

    .apply-section { padding: 80px 24px; background: var(--bg); }
    .apply-grid { display: grid; grid-template-columns: 1fr 1.4fr; gap: 52px; align-items: start; max-width: 1060px; margin: 0 auto; }
    @media (max-width: 820px) { .apply-grid { grid-template-columns: 1fr; gap: 32px; } }

    .apply-sidebar h2 { font-size: 25px; font-weight: 700; margin-bottom: 14px; }
    .apply-sidebar p { font-size: 15px; color: var(--muted); line-height: 1.7; margin-bottom: 16px; }
    .checklist { list-style: none; padding: 0; border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; background: var(--surface); }
    .checklist li {
        padding: 13px 16px; font-size: 14px; line-height: 1.55; color: var(--ink);
        border-bottom: 1px solid var(--line);
    }
    .checklist li:last-child { border-bottom: none; }
    .checklist li strong { display: block; font-weight: 600; margin-bottom: 2px; }
    .checklist li span { color: var(--muted); font-size: 13px; }

    .apply-form-card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 34px; }
    .apply-form-card h2 { font-size: 21px; font-weight: 700; margin-bottom: 4px; }
    .apply-form-card .sub { font-size: 14px; color: var(--muted); margin-bottom: 22px; line-height: 1.6; }

    .form-section-title {
        font-size: 15px; font-weight: 600; color: var(--brand-dark);
        border-bottom: 2px solid var(--brand-light); padding-bottom: 8px; margin: 28px 0 16px;
    }
    .form-section-title:first-of-type { margin-top: 0; }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    @media (max-width: 560px) { .form-row { grid-template-columns: 1fr; } }

    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--ink); }
    .form-group label .req { color: #a12626; margin-left: 2px; }
    .form-group label .hint { font-weight: 400; color: var(--muted); font-size: 12px; }
    .form-group input[type=text],
    .form-group input[type=email],
    .form-group input[type=tel],
    .form-group input[type=url],
    .form-group select,
    .form-group textarea {
        width: 100%; padding: 11px 13px; border: 1px solid var(--line);
        border-radius: 9px; font-family: var(--font); font-size: 15px; background: #fff;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
        outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(84,180,53,.12);
    }
    .form-group input[type=file] {
        width: 100%; padding: 9px 12px; border: 1px dashed var(--line);
        border-radius: 9px; font-size: 14px; background: var(--brand-light); cursor: pointer;
    }
    .form-group textarea { min-height: 96px; resize: vertical; }

    .consent-group { margin-top: 10px; }
    .consent-item {
        display: flex; gap: 10px; padding: 11px 0; border-bottom: 1px solid var(--line);
        font-size: 13px; color: var(--muted); align-items: flex-start; line-height: 1.6;
    }
    .consent-item:last-child { border-bottom: none; }
    .consent-item input[type=checkbox] { width: auto; margin-top: 2px; accent-color: var(--brand); flex-shrink: 0; }

    .submit-btn {
        width: 100%; margin-top: 20px; padding: 15px; background: var(--brand); color: #fff;
        border: none; border-radius: 10px; font-family: var(--font); font-size: 16px;
        font-weight: 700; cursor: pointer;
    }
    .submit-btn:hover { background: var(--brand-dark); }
    .form-note { font-size: 12px; color: var(--muted); text-align: center; margin-top: 12px; line-height: 1.6; }

    .alert-error-box {
        background: #fdecec; border: 1px solid #f5c2c2; color: #a12626;
        border-radius: 9px; padding: 12px 16px; font-size: 13px; margin-bottom: 16px;
    }
    .alert-error-box ul { margin: 0; padding-left: 18px; }
    .alert-ok-box {
        background: #e8f3ea; border: 1px solid #bcdcc3; color: #256b34;
        border-radius: 9px; padding: 14px 16px; font-size: 14px; margin-bottom: 16px; line-height: 1.6;
    }
</style>
@endpush

@section('content')

<section class="page-hero">
    <h1>Send your applicants to a notary you can vouch for</h1>
    <p>
        Government bodies, embassies, law firms and companies partner with us to get
        their applicants' documents notarized properly, at a rate agreed in advance,
        by our own notary public — with a link of your own to hand out.
    </p>
    <div class="btns">
        <a href="#apply" class="btn btn-primary btn-lg">Apply to partner</a>
        <a href="{{ route('how-it-works') }}" class="btn btn-white btn-lg">How it works</a>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="center">
            <div class="section-label">For organizations</div>
            <h2 class="section-title">What a partnership gives you</h2>
            <p class="section-sub">
                This is for bodies that send notarization work. If you are a notary public
                looking to join our panel,
                <a href="{{ route('notary.apply') }}" style="color:var(--brand-dark); font-weight:600;">apply here instead</a>.
            </p>
        </div>

        <div class="why-grid">
            <div class="why-card">
                <h3>A link of your own</h3>
                <p>A page carrying your name, and a short code for a letter or a counter. Anyone who
                   arrives through it is recorded as yours.</p>
            </div>
            <div class="why-card">
                <h3>A rate agreed in advance</h3>
                <p>Your applicants are quoted the figure we settle with you — not our public price,
                   and not a discount off it.</p>
            </div>
            <div class="why-card">
                <h3>Our own notary public</h3>
                <p>Work that comes through you is handled by the platform's own notary, not
                   allocated around a marketplace.</p>
            </div>
            <div class="why-card">
                <h3>Your own portal</h3>
                <p>Sign in to see how much work you have sent, what state it is in, and — where
                   your arrangement includes one — what you have earned.</p>
            </div>
        </div>
    </div>
</section>

<section class="apply-section" id="apply">
    <div class="apply-grid">
        <div class="apply-sidebar">
            <h2>Before you start</h2>
            <p>
                It takes about five minutes. We read every application by hand and come
                back to you on the contact details you give us — usually within two
                working days.
            </p>

            <ul class="checklist">
                <li>
                    <strong>Your registration document</strong>
                    <span>A CAC certificate, an establishing instrument, or the request on your
                          own letterhead. Required.</span>
                </li>
                <li>
                    <strong>A letter of authorisation</strong>
                    <span>If the person applying is not the body's own officer. Optional.</span>
                </li>
                <li>
                    <strong>A logo</strong>
                    <span>For your landing page. Optional — we will not hold the application up
                          for want of a file.</span>
                </li>
                <li>
                    <strong>Someone we can reach</strong>
                    <span>A name, a role, an email and a phone number. We use these to settle
                          the rate and to come back to you if something here needs correcting.</span>
                </li>
            </ul>

            <p style="margin-top:16px; font-size:13.5px;">
                We do not ask you to propose a price or a commission on this form. That is a
                conversation, and it happens after we have read what you sent.
            </p>
        </div>

        <div class="apply-form-card">
            <h2>Apply to partner</h2>
            <div class="sub">Everything marked with a red asterisk is required.</div>

            @if (session('status'))
                <div class="alert-ok-box">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert-error-box">
                    <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('organization.apply.store') }}" enctype="multipart/form-data">
                @csrf

                <div class="form-section-title">About the organization</div>

                <div class="form-group">
                    <label for="name">Organization name<span class="req">*</span></label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="sector">What kind of body<span class="req">*</span></label>
                        <select id="sector" name="sector" required>
                            <option value="">Choose one…</option>
                            @foreach (\App\Http\Requests\Organization\OrganizationApplicationRequest::SECTORS as $value => $label)
                                <option value="{{ $value }}" @selected(old('sector') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="registration_number">
                            Registration number <span class="hint">(RC / CAC, if you have one)</span>
                        </label>
                        <input id="registration_number" type="text" name="registration_number"
                               value="{{ old('registration_number') }}">
                    </div>
                </div>

                <div class="form-group">
                    <label for="website">Website <span class="hint">(optional)</span></label>
                    <input id="website" type="url" name="website" value="{{ old('website') }}"
                           placeholder="https://">
                </div>

                <div class="form-group">
                    <label for="address">Address<span class="req">*</span></label>
                    <textarea id="address" name="address" required style="min-height:70px;">{{ old('address') }}</textarea>
                </div>

                <div class="form-group">
                    <label for="about">What your organization does, and why you need notarization<span class="req">*</span></label>
                    <textarea id="about" name="about" required>{{ old('about') }}</textarea>
                </div>

                <div class="form-group">
                    <label for="expected_volume">Roughly how much work you expect to send<span class="req">*</span></label>
                    <select id="expected_volume" name="expected_volume" required>
                        <option value="">Choose one…</option>
                        @foreach (\App\Http\Requests\Organization\OrganizationApplicationRequest::VOLUMES as $value => $label)
                            <option value="{{ $value }}" @selected(old('expected_volume') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-section-title">Who we should speak to</div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="contact_name">Full name<span class="req">*</span></label>
                        <input id="contact_name" type="text" name="contact_name" value="{{ old('contact_name') }}" required>
                    </div>
                    <div class="form-group">
                        <label for="contact_role">Their role<span class="req">*</span></label>
                        <input id="contact_role" type="text" name="contact_role" value="{{ old('contact_role') }}"
                               placeholder="e.g. Head of Consular Services" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="contact_email">Email address<span class="req">*</span></label>
                        <input id="contact_email" type="email" name="contact_email" value="{{ old('contact_email') }}" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone number<span class="req">*</span></label>
                        <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" required>
                    </div>
                </div>

                <div class="form-section-title">Supporting documents</div>

                <div class="form-group">
                    <label for="registration">
                        Certificate of registration, or your request on letterhead<span class="req">*</span>
                        <span class="hint">— PDF or image, up to 10MB</span>
                    </label>
                    <input id="registration" type="file" name="registration"
                           accept=".pdf,.jpg,.jpeg,.png" required>
                </div>

                <div class="form-group">
                    <label for="authorisation">
                        Letter of authorisation <span class="hint">(optional — PDF or image, up to 10MB)</span>
                    </label>
                    <input id="authorisation" type="file" name="authorisation" accept=".pdf,.jpg,.jpeg,.png">
                </div>

                <div class="form-group">
                    <label for="logo">
                        Logo or crest <span class="hint">(optional — for your landing page, up to 4MB)</span>
                    </label>
                    <input id="logo" type="file" name="logo" accept=".jpg,.jpeg,.png,.webp">
                </div>

                <div class="form-section-title">Confirmations</div>

                <div class="consent-group">
                    <div class="consent-item">
                        <input id="accuracy_consent" type="checkbox" name="accuracy_consent" value="1"
                               @checked(old('accuracy_consent')) required>
                        <label for="accuracy_consent" style="font-weight:400; margin:0;">
                            The information above is accurate, and I am authorised to make this
                            application on the organization's behalf.
                        </label>
                    </div>
                    <div class="consent-item">
                        <input id="contact_consent" type="checkbox" name="contact_consent" value="1"
                               @checked(old('contact_consent')) required>
                        <label for="contact_consent" style="font-weight:400; margin:0;">
                            {{ config('app.name') }} may contact me about this application, including
                            to correct anything that needs it.
                        </label>
                    </div>
                </div>

                <button class="submit-btn" type="submit">Submit application</button>
                <p class="form-note">
                    Nothing goes live until we have agreed terms with you. Already a partner?
                    <a href="{{ route('organization.login') }}" style="color:var(--brand-dark); font-weight:600;">Sign in to your portal</a>.
                </p>
            </form>
        </div>
    </div>
</section>

@endsection
