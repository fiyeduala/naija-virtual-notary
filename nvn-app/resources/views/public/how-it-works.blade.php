@extends('layouts.public', [
    'title' => 'How It Works — Naija Virtual Notary',
    'description' => 'Learn how online notarization works. Four simple steps from uploading your document to receiving your notarized PDF.',
])

@push('styles')
<style>
    .page-hero {
        background: linear-gradient(135deg, #0f1a0b, #1a3011 60%, #2a5020);
        color: #fff; padding: 80px 24px 72px; text-align: center;
    }
    .page-hero h1 { font-size: clamp(32px, 4vw, 52px); font-weight: 800; color: #fff; margin-bottom: 16px; }
    .page-hero p { font-size: 18px; color: rgba(255,255,255,.8); max-width: 540px; margin: 0 auto; line-height: 1.6; }

    .steps-list { margin-top: 56px; display: flex; flex-direction: column; gap: 40px; }
    .step-row {
        display: grid; grid-template-columns: 80px 1fr; gap: 28px; align-items: start;
    }
    .step-badge {
        width: 80px; height: 80px; background: var(--brand); color: #fff;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-size: 26px; font-weight: 800; flex-shrink: 0;
    }
    .step-row:not(:last-child) .step-badge-wrap::after {
        content: ''; display: block; width: 2px; height: 32px;
        background: var(--brand-light); border: 1px dashed var(--brand);
        margin: 8px auto 0;
    }
    .step-content { padding-top: 8px; }
    .step-content h3 { font-size: 20px; font-weight: 700; margin-bottom: 10px; }
    .step-content p { font-size: 15px; color: var(--muted); line-height: 1.7; }

    .faq-section { background: var(--brand-light); }
    .faq-list { margin-top: 40px; display: flex; flex-direction: column; gap: 0; border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; }
    .faq-item { background: var(--surface); border-bottom: 1px solid var(--line); }
    .faq-item:last-child { border-bottom: none; }
    .faq-q {
        width: 100%; text-align: left; background: none; border: none; padding: 20px 24px;
        font-family: var(--font); font-size: 16px; font-weight: 600; color: var(--ink);
        cursor: pointer; display: flex; justify-content: space-between; align-items: center;
    }
    .faq-q:hover { background: var(--brand-light); }
    .faq-q .arrow { font-size: 12px; color: var(--brand); transition: transform .2s; }
    .faq-q.open .arrow { transform: rotate(180deg); }
    .faq-a { padding: 0 24px 20px; font-size: 15px; color: var(--muted); line-height: 1.7; display: none; }
    .faq-a.open { display: block; }

    .cta-strip { background: linear-gradient(135deg, #54B435, #3d8a27); color: #fff; text-align: center; padding: 72px 24px; }
    .cta-strip h2 { font-size: clamp(24px, 3vw, 38px); font-weight: 700; margin-bottom: 12px; color: #fff; }
    .cta-strip p { font-size: 16px; color: rgba(255,255,255,.85); margin-bottom: 32px; }
</style>
@endpush

@section('content')

<section class="page-hero">
    <h1>How It Works</h1>
    <p>Get your documents notarized in four simple steps — entirely online, entirely secure.</p>
</section>

<section class="section">
    <div class="container" style="max-width:760px;">
        <div class="section-label center">The Process</div>
        <h2 class="section-title center">From upload to notarized — in 4 steps</h2>
        <div class="steps-list">
            <div class="step-row">
                <div class="step-badge-wrap">
                    <div class="step-badge">1</div>
                </div>
                <div class="step-content">
                    <h3>Upload Your Document</h3>
                    <p>Submit your file through our secure online portal. We use encrypted uploads to keep your information safe and confidential. Accepted document types include affidavits, contracts, property paperwork, business documents, and more.</p>
                </div>
            </div>
            <div class="step-row">
                <div class="step-badge-wrap">
                    <div class="step-badge">2</div>
                </div>
                <div class="step-content">
                    <h3>Verify Your Identity &amp; Meet Online</h3>
                    <p>Connect via a secure video call with one of our licensed notaries to confirm your identity and witness your signature. There are no in-person requirements — it all happens from wherever you are in the world.</p>
                </div>
            </div>
            <div class="step-row">
                <div class="step-badge-wrap">
                    <div class="step-badge">3</div>
                </div>
                <div class="step-content">
                    <h3>Download Your Notarized Document</h3>
                    <p>Once the notarization is complete, your signed and sealed document will be ready for immediate download directly from your dashboard. You'll receive an email notification as soon as it's ready.</p>
                </div>
            </div>
            <div class="step-row">
                <div class="step-badge-wrap">
                    <div class="step-badge">4</div>
                </div>
                <div class="step-content">
                    <h3>Receive a Physical Copy (Optional)</h3>
                    <p>Need a stamped hard copy delivered to your address? We can arrange that too. Simply select the physical copy option during your request and we'll handle delivery.</p>
                </div>
            </div>
        </div>
        <div style="text-align:center;margin-top:48px;">
            <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Start your notarization</a>
        </div>
    </div>
</section>

{{-- FAQ --}}
<section class="section faq-section">
    <div class="container" style="max-width:760px;">
        <div class="section-label center">FAQ</div>
        <h2 class="section-title center">Frequently asked questions</h2>
        <div class="faq-list">
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)">
                    Is online notarization legally valid? <span class="arrow"><x-heroicon-o-chevron-down style="width:16px;height:16px;display:inline-block;vertical-align:middle;"/></span>
                </button>
                <div class="faq-a">
                    Yes. Our licensed notaries operate under Nigerian law. Electronic notarization is recognized domestically and internationally. Every document bears the notary's official seal and is SHA-256 hashed for tamper-proof verification.
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)">
                    Do I need to be physically present? <span class="arrow"><x-heroicon-o-chevron-down style="width:16px;height:16px;display:inline-block;vertical-align:middle;"/></span>
                </button>
                <div class="faq-a">
                    No — the entire process is online. You connect with your notary via a secure video call. No travel, no office visit, no queues.
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)">
                    What types of documents can be notarized? <span class="arrow"><x-heroicon-o-chevron-down style="width:16px;height:16px;display:inline-block;vertical-align:middle;"/></span>
                </button>
                <div class="faq-a">
                    We notarize a wide range of documents including affidavits, statutory declarations, contracts, power of attorney, property paperwork, business agreements, and more. If you're unsure whether your document qualifies, contact us.
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)">
                    How long does it take? <span class="arrow"><x-heroicon-o-chevron-down style="width:16px;height:16px;display:inline-block;vertical-align:middle;"/></span>
                </button>
                <div class="faq-a">
                    Most documents are notarized within 24 hours. Many can be completed in as little as 30 minutes, depending on notary availability when you book.
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)">
                    Can I get a physical (stamped) copy? <span class="arrow"><x-heroicon-o-chevron-down style="width:16px;height:16px;display:inline-block;vertical-align:middle;"/></span>
                </button>
                <div class="faq-a">
                    Yes. When submitting your request you can tick the "physical copy" option and we will arrange for a stamped hard copy to be delivered to your address.
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)">
                    What currencies are accepted? <span class="arrow"><x-heroicon-o-chevron-down style="width:16px;height:16px;display:inline-block;vertical-align:middle;"/></span>
                </button>
                <div class="faq-a">
                    We accept payment in both Nigerian Naira (NGN) and US Dollars (USD), making it convenient for clients both in Nigeria and abroad.
                </div>
            </div>
        </div>
    </div>
</section>

<section class="cta-strip">
    <h2>Ready to notarize?</h2>
    <p>Create an account and upload your first document in minutes.</p>
    <a href="{{ route('register') }}" class="btn btn-white btn-lg">Get started free</a>
</section>

<script>
function toggleFaq(btn) {
    const answer = btn.nextElementSibling;
    const isOpen = btn.classList.contains('open');
    document.querySelectorAll('.faq-q.open').forEach(b => {
        b.classList.remove('open');
        b.nextElementSibling.classList.remove('open');
    });
    if (!isOpen) {
        btn.classList.add('open');
        answer.classList.add('open');
    }
}
</script>
@endsection
