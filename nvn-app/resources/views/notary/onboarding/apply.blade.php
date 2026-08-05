@extends('layouts.app', ['title' => 'Partner application'])

@push('styles')
<style>:root { --page-w: 780px; }</style>
@endpush

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('home') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Home
        </a>
        <h1>Partner with Naija Virtual Notary</h1>
        <div class="sub">Apply to notarize on the platform. You'll verify your email and pay the onboarding fee; our team then reviews your credentials.</div>
    </div>
</div>

<div class="shell">

<form method="POST" action="{{ route('notary.apply') }}" enctype="multipart/form-data" class="card">
    @csrf

    <h2>Your details</h2>
    <div class="grid-2">
        <div><label for="full_name">Full name</label><input id="full_name" type="text" name="full_name" value="{{ old('full_name') }}" required></div>
        <div><label for="email">Email address</label><input id="email" type="email" name="email" value="{{ old('email') }}" required></div>
    </div>
    <div class="grid-2">
        <div><label for="phone">Phone number</label><input id="phone" type="tel" name="phone" value="{{ old('phone') }}" required></div>
        <div>
            <label for="entity_type">Individual or agency</label>
            <select id="entity_type" name="entity_type" required>
                <option value="individual" @selected(old('entity_type')==='individual')>Individual Notary</option>
                <option value="agency" @selected(old('entity_type')==='agency')>Notary Agency</option>
            </select>
        </div>
    </div>
    <label for="organization_name">Organization name (if agency)</label>
    <input id="organization_name" type="text" name="organization_name" value="{{ old('organization_name') }}">

    <div class="grid-2">
        <div><label for="password">Password</label><input id="password" type="password" name="password" required></div>
        <div><label for="password_confirmation">Confirm password</label><input id="password_confirmation" type="password" name="password_confirmation" required></div>
    </div>

    <h2 style="margin-top:22px;">Credentials</h2>
    <div class="grid-2">
        <div><label for="license_ref">Notary license / appointment ref. number</label><input id="license_ref" type="text" name="license_ref" value="{{ old('license_ref') }}" required></div>
        <div><label for="year_of_oath">Year of oath taking</label><input id="year_of_oath" type="number" name="year_of_oath" value="{{ old('year_of_oath') }}" min="1950" max="{{ date('Y') }}" required></div>
    </div>
    <label for="experience">Years of experience and nature of documents notarized</label>
    <textarea id="experience" name="experience" required>{{ old('experience') }}</textarea>

    <label>Specialties</label>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px;">
        @foreach (\App\Enums\Specialty::cases() as $s)
            <label style="font-weight:400; display:flex; gap:8px; align-items:center; margin:4px 0;">
                <input type="checkbox" name="specialties[]" value="{{ $s->value }}" style="width:auto;"> {{ $s->label() }}
            </label>
        @endforeach
    </div>

    <label for="motivation" style="margin-top:14px;">Why do you want to partner with Naija Virtual Notary?</label>
    <textarea id="motivation" name="motivation" required>{{ old('motivation') }}</textarea>

    <h2 style="margin-top:22px;">Document uploads</h2>
    <div class="grid-2">
        <div><label for="valid_id">Valid ID document</label><input id="valid_id" type="file" name="valid_id" accept=".pdf,.jpg,.jpeg,.png" required></div>
        <div><label for="oath_of_office">Notary oath of office</label><input id="oath_of_office" type="file" name="oath_of_office" accept=".pdf,.jpg,.jpeg,.png" required></div>
    </div>

    <h2 style="margin-top:22px;">Consents</h2>
    <label style="font-weight:400; display:flex; gap:8px;"><input type="checkbox" name="accuracy_consent" value="1" style="width:auto;"> I confirm all information provided is accurate and I consent to verification.</label>
    <label style="font-weight:400; display:flex; gap:8px;"><input type="checkbox" name="commission_consent" value="1" style="width:auto;"> I agree that Naija Virtual Notary retains 50% of each notarization fee, and I receive 50%.</label>
    <label style="font-weight:400; display:flex; gap:8px;"><input type="checkbox" name="delegation_consent" value="1" style="width:auto;"> I authorize Naija Virtual Notary to complete a paid request on my behalf — applying my signature, stamp and seal — where I have not completed it myself. My name remains on the document and I am credited at my agreed rate.</label>

    <button class="btn btn-block" type="submit" style="margin-top:20px;">Submit application</button>
</form>

</div>
@endsection
