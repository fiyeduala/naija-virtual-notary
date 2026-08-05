@extends('layouts.app', ['title' => 'New notarization request'])

@push('styles')
<style>:root { --page-w: 860px; }</style>
@endpush

@push('styles')
<style>
.upload-input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
    z-index: 2;
}
.upload-zone {
    position: relative;
    border: 2px dashed var(--line);
    border-radius: var(--radius);
    background: #fafbfc;
    transition: border-color .2s, background .2s;
    overflow: hidden;
}
.upload-zone:hover, .upload-zone.drag-over {
    border-color: var(--brand);
    background: var(--brand-light);
}
.upload-zone.has-file {
    border-color: var(--success);
    background: var(--success-bg);
}
.upload-label {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 24px 20px;
    cursor: pointer;
    text-align: center;
    color: var(--muted);
    min-height: 110px;
    margin: 0;
    font-weight: 400;
}
.upload-zone:hover .upload-label,
.upload-zone.drag-over .upload-label { color: var(--brand-dark); }
.upload-zone.has-file .upload-label  { color: var(--success); }
.upload-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--ink);
    line-height: 1.3;
}
.upload-zone:hover .upload-title,
.upload-zone.drag-over .upload-title { color: var(--brand-dark); }
.upload-zone.has-file .upload-title  { color: var(--success); }
.upload-sub { font-size: 12px; color: var(--muted); }
.upload-filename {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--muted);
    max-width: 320px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    margin-top: 2px;
}
.upload-zone.has-file .upload-filename { color: var(--success); }
</style>
@endpush

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('client.dashboard') }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            Back to dashboard
        </a>
        <h1>New notarization request</h1>
        <div class="sub">Upload your already-signed document, a valid ID, and provide the notarization details</div>
    </div>
</div>

<div class="shell">
    <form method="POST" action="{{ route('client.request.store') }}" enctype="multipart/form-data" class="card">
        @csrf

        {{-- Personal details --}}
        <h2 style="margin-bottom:18px;">Your details</h2>
        <div class="grid-2">
            <div>
                <label for="first_name">First name</label>
                <input id="first_name" type="text" name="first_name" value="{{ old('first_name', $first_name) }}" required>
            </div>
            <div>
                <label for="last_name">Last name</label>
                <input id="last_name" type="text" name="last_name" value="{{ old('last_name', $last_name) }}" required>
            </div>
        </div>
        <div class="grid-2">
            <div>
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required>
            </div>
            <div>
                <label for="phone">Phone number</label>
                <input id="phone" type="tel" name="phone" value="{{ old('phone', $phone) }}" required>
            </div>
        </div>

        {{-- Document details --}}
        <h2 style="margin-top:28px; margin-bottom:18px;">Document details</h2>
        <label for="document_use">What is your reason for notarization?</label>
        <textarea id="document_use" name="document_use" required>{{ old('document_use') }}</textarea>

        <label>How would you like to sign your document?</label>
        <div style="display:flex; gap:18px; margin-top:6px; margin-bottom:10px; flex-wrap:wrap;">
            <label style="font-weight:400; display:flex; gap:8px; align-items:center; cursor:pointer;">
                <input type="radio" name="signing_method" value="presigned" style="width:auto; accent-color:var(--brand);" checked onclick="toggleSigning('presigned')">
                I'll upload an already-signed document
            </label>
            <label style="font-weight:400; display:flex; gap:8px; align-items:center; cursor:pointer;">
                <input type="radio" name="signing_method" value="inapp" style="width:auto; accent-color:var(--brand);" onclick="toggleSigning('inapp')">
                Sign in the app (draw my signature now)
            </label>
        </div>

        <div id="presign-note" class="text-sm muted" style="margin-bottom:10px;">Please sign and fill the document before uploading.</div>
        <div id="inapp-sig" style="display:none; margin-bottom:16px;">
            <label>Draw your signature below</label>
            <canvas id="sig-canvas" width="540" height="130"
                style="border:1px solid var(--line); border-radius:8px; background:#fff; cursor:crosshair; touch-action:none; max-width:100%;"></canvas>
            <div style="display:flex; gap:10px; margin-top:6px; align-items:center;">
                <button type="button" onclick="clearSig()" class="btn btn-ghost btn-sm">Clear</button>
                <span class="text-sm muted">Your signature will be saved with your request.</span>
            </div>
            <input type="hidden" name="client_signature" id="sig-data">
        </div>

        <label style="margin-bottom:8px;">Upload document to be notarized</label>
        <div class="upload-zone" id="zone-document">
            <input id="document" type="file" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required class="upload-input">
            <label for="document" class="upload-label">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                <span class="upload-title">Click to upload document</span>
                <span class="upload-sub">PDF, DOC, DOCX, JPG, PNG accepted</span>
                <span class="upload-filename" id="doc-name">No file chosen</span>
            </label>
        </div>

        <label style="margin-bottom:8px; margin-top:18px;">Additional documents <span class="text-sm muted">(optional)</span></label>
        <div class="upload-zone" id="zone-additional">
            <input id="additional" type="file" name="additional[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" multiple class="upload-input">
            <label for="additional" class="upload-label">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <span class="upload-title">Click to upload additional files</span>
                <span class="upload-sub">You can select multiple files</span>
                <span class="upload-filename" id="add-name">No files chosen</span>
            </label>
        </div>

        <label style="margin-bottom:8px; margin-top:18px;">Upload valid means of identification</label>
        <div class="upload-zone" id="zone-id">
            <input id="identification" type="file" name="identification" accept=".pdf,.jpg,.jpeg,.png" required class="upload-input">
            <label for="identification" class="upload-label">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                <span class="upload-title">Click to upload ID document</span>
                <span class="upload-sub">NIN slip, Passport, Driver's licence, Voter's card (PDF or image)</span>
                <span class="upload-filename" id="id-name">No file chosen</span>
            </label>
        </div>

        <label for="currency">Preferred currency</label>
        <select id="currency" name="currency" required>
            @foreach (config('nvn.currencies') as $cur)
                <option value="{{ $cur }}" @selected(old('currency')===$cur)>{{ $cur }}</option>
            @endforeach
        </select>

        {{-- Delivery --}}
        <h2 style="margin-top:28px; margin-bottom:14px;">Delivery</h2>
        <label style="font-size:14px;">Do you need a hard copy sent to your address?
            <span class="text-sm muted">(extra cost applies)</span>
        </label>
        <div style="display:flex; gap:20px; margin-top:8px; margin-bottom:6px; flex-wrap:wrap;">
            <label style="font-weight:400; display:flex; gap:8px; align-items:center; cursor:pointer;">
                <input type="radio" name="hard_copy" value="0" style="width:auto; accent-color:var(--brand);" checked
                    onclick="document.getElementById('addr').style.display='none'">
                No (email soft copy is fine)
            </label>
            <label style="font-weight:400; display:flex; gap:8px; align-items:center; cursor:pointer;">
                <input type="radio" name="hard_copy" value="1" style="width:auto; accent-color:var(--brand);"
                    onclick="document.getElementById('addr').style.display='block'">
                Yes (send hard copy)
            </label>
        </div>

        <div id="addr" style="display:none; margin-top:14px; background:var(--bg); border:1px solid var(--line); border-radius:var(--radius); padding:18px 20px;">
            <label for="street">Street address</label>
            <input id="street" type="text" name="street" value="{{ old('street') }}">
            <label for="apartment">Apartment, suite, etc.</label>
            <input id="apartment" type="text" name="apartment" value="{{ old('apartment') }}">
            <div class="grid-2">
                <div>
                    <label for="city">City</label>
                    <input id="city" type="text" name="city" value="{{ old('city') }}">
                </div>
                <div>
                    <label for="state">State / province</label>
                    <input id="state" type="text" name="state" value="{{ old('state') }}">
                </div>
            </div>
            <div class="grid-2">
                <div>
                    <label for="postal_code">ZIP / postal code</label>
                    <input id="postal_code" type="text" name="postal_code" value="{{ old('postal_code') }}">
                </div>
                <div>
                    <label for="country">Country</label>
                    <select id="country" name="country">
                        <option value="">— Please choose —</option>
                        @foreach (config('nvn.countries') as $c)
                            <option value="{{ $c }}" @selected(old('country')===$c)>{{ $c }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Consent --}}
        <h2 style="margin-top:28px; margin-bottom:14px;">Consent</h2>
        <label style="font-weight:400; display:flex; gap:10px; align-items:flex-start; cursor:pointer;">
            <input type="checkbox" name="consent" value="1" style="width:auto; margin-top:3px; accent-color:var(--brand);" required>
            <span class="text-sm">I agree with the privacy policy and terms and conditions. I also conscientiously state that this document will be used for legal means.</span>
        </label>

        <button class="btn btn-block" type="submit" style="margin-top:24px; justify-content:center;">
            Continue to choose a notary &rarr;
        </button>
    </form>
</div>

<script>
function toggleSigning(method) {
    document.getElementById('presign-note').style.display = method === 'presigned' ? '' : 'none';
    document.getElementById('inapp-sig').style.display   = method === 'inapp'     ? '' : 'none';
}

// ── File upload zone wiring ──────────────────────────────────────────────
(function () {
    var zones = [
        { inputId: 'document',       nameId: 'doc-name', zoneId: 'zone-document',   multi: false },
        { inputId: 'additional',     nameId: 'add-name', zoneId: 'zone-additional', multi: true  },
        { inputId: 'identification', nameId: 'id-name',  zoneId: 'zone-id',         multi: false },
    ];

    zones.forEach(function (z) {
        var input = document.getElementById(z.inputId);
        var nameEl = document.getElementById(z.nameId);
        var zone  = document.getElementById(z.zoneId);
        if (!input || !nameEl || !zone) return;

        function updateName(files) {
            if (!files || files.length === 0) {
                nameEl.textContent = z.multi ? 'No files chosen' : 'No file chosen';
                zone.classList.remove('has-file');
            } else if (files.length === 1) {
                nameEl.textContent = files[0].name;
                zone.classList.add('has-file');
            } else {
                nameEl.textContent = files.length + ' files selected';
                zone.classList.add('has-file');
            }
        }

        input.addEventListener('change', function () { updateName(this.files); });

        zone.addEventListener('dragover',  function (e) { e.preventDefault(); zone.classList.add('drag-over'); });
        zone.addEventListener('dragleave', function ()  { zone.classList.remove('drag-over'); });
        zone.addEventListener('drop',      function (e) {
            e.preventDefault();
            zone.classList.remove('drag-over');
            if (e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                updateName(input.files);
            }
        });
    });
})();

(function () {
    const canvas = document.getElementById('sig-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let drawing = false;

    function pos(e) {
        const r = canvas.getBoundingClientRect();
        const t = e.touches ? e.touches[0] : e;
        return { x: (t.clientX - r.left) * (canvas.width / r.width), y: (t.clientY - r.top) * (canvas.height / r.height) };
    }

    canvas.addEventListener('mousedown',  e => { drawing = true; ctx.beginPath(); const p = pos(e); ctx.moveTo(p.x, p.y); });
    canvas.addEventListener('mousemove',  e => { if (!drawing) return; const p = pos(e); ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#1f2933'; ctx.lineTo(p.x, p.y); ctx.stroke(); });
    canvas.addEventListener('mouseup',    () => { drawing = false; capture(); });
    canvas.addEventListener('touchstart', e => { e.preventDefault(); drawing = true; ctx.beginPath(); const p = pos(e); ctx.moveTo(p.x, p.y); }, { passive: false });
    canvas.addEventListener('touchmove',  e => { e.preventDefault(); if (!drawing) return; const p = pos(e); ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#1f2933'; ctx.lineTo(p.x, p.y); ctx.stroke(); }, { passive: false });
    canvas.addEventListener('touchend',   () => { drawing = false; capture(); });

    function capture() {
        document.getElementById('sig-data').value = canvas.toDataURL('image/png');
    }
})();

function clearSig() {
    const canvas = document.getElementById('sig-canvas');
    canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
    document.getElementById('sig-data').value = '';
}
</script>
@endsection
