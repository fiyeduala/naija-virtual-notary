@extends('layouts.app', ['title' => 'New offsite job'])

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('notary.offsite.index') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to offsite jobs
        </a>
        <h1>New offsite job</h1>
        <div class="sub">
            Upload what you agreed to notarize. Nothing is charged until you choose to pay,
            and you can add or remove documents up to that moment.
        </div>
    </div>
</div>

<div class="shell" style="max-width:720px;">
    <form method="POST" action="{{ route('notary.offsite.store') }}" enctype="multipart/form-data" class="card">
        @csrf

        <label for="described_as" style="margin-bottom:8px;">What is this?</label>
        <input id="described_as" type="text" name="described_as" maxlength="500" required
               value="{{ old('described_as') }}"
               placeholder="e.g. Affidavit of loss — Mrs A. Okoro, walk-in">
        <div class="text-sm muted" style="margin-top:6px;">
            For your own records only. No client sees this, and it is how you will recognise
            the job in your list later.
        </div>

        @if ($isAdmin)
        {{-- Only an admin is ever asked this. A notary has exactly one answer —
             their own profile — and being made to pick it would only imply they
             could place work under somebody else's marks, which they cannot. --}}
        <label for="notary_id" style="margin-bottom:8px; margin-top:22px;">Whose marks go on it?</label>
        <select id="notary_id" name="notary_id" required>
            @foreach ($notaries as $n)
            <option value="{{ $n->id }}" @selected((int) old('notary_id', $ownNotary) === $n->id)>
                {{ $n->user->full_name }}@if ($n->id === $ownNotary) — the platform's own notary @endif
            </option>
            @endforeach
        </select>
        <div class="text-sm muted" style="margin-top:6px;">
            The signature, stamp and seal placed on the document are this notary's, and stay
            theirs. Pick yourself for work the desk took on; pick a partner for a job they did
            offsite and sent in for sealing.
        </div>
        @endif

        <label style="margin-bottom:8px; margin-top:22px;">Documents to notarize</label>
        <div class="upload-zone" id="zone-documents">
            <input id="documents" type="file" name="documents[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                   multiple required class="upload-input">
            <label for="documents" class="upload-label">
                <x-heroicon-o-document-arrow-up style="width:24px;height:24px;"/>
                <span class="upload-title">Click to upload</span>
                <span class="upload-sub">PDF, DOC, DOCX, JPG, PNG — up to 20 files, 15 MB each</span>
                <span class="upload-filename" id="doc-names">No files chosen</span>
            </label>
        </div>

        {{-- Said before the upload button rather than after it: this is the one
             thing about the arrangement a notary must not be surprised by. --}}
        <div class="alert" style="margin-top:22px; background:var(--warning-bg); border-color:var(--warning); display:flex; gap:10px; align-items:flex-start;">
            <x-heroicon-o-information-circle style="width:18px;height:18px;flex:none;margin-top:1px;"/>
            <div class="text-sm">
                @if ($isAdmin)
                    <strong>Nothing is charged here.</strong>
                    On the next screen you write down what the client actually paid you —
                    cash, transfer, however it arrived — and the job opens for sealing.
                @elseif ($feeMinor === 0)
                    <strong>{{ $fee }} per document.</strong>
                    Offsite sealing is free at the moment, so nothing will be charged.
                @else
                    <strong>{{ $fee }} per document.</strong>
                    Charged once, on this screen's next step, before the editor opens.
                    Whatever you charged your own client is yours — the platform takes no share of it.
                @endif
            </div>
        </div>

        <button type="submit" class="btn btn-block" style="margin-top:22px;">Continue</button>
    </form>
</div>

<script>
document.getElementById('documents').addEventListener('change', function () {
    var n = this.files.length;
    document.getElementById('doc-names').textContent =
        n === 0 ? 'No files chosen'
        : n === 1 ? this.files[0].name
        : n + ' files chosen';
});
</script>
@endsection
