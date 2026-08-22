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
                <strong>{{ $fee }} per document.</strong>
                @if ($feeMinor === 0)
                    Offsite sealing is free at the moment, so nothing will be charged.
                @else
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
