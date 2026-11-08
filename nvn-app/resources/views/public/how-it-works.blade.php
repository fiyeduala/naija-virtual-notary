@extends('layouts.public', [
    'title' => 'How It Works — Naija Virtual Notary',
    'description' => 'Learn how online notarization works. Four simple steps from uploading your document to receiving your notarized PDF.',
])

@push('styles')
<style>
    /*
    | page-hero and cta-strip come from layouts/public. Only the journey and the
    | FAQ are styled here.
    |
    | The four steps below follow the real status chain a request moves through
    | — draft, submitted, paid, accepted, scheduled, in_verification, notarizing,
    | completed (App\Enums\RequestStatus). The page used to skip payment and
    | notary selection entirely, which made the journey read as three steps that
    | happen to you rather than four you take.
    */

    .journey { display: flex; flex-direction: column; gap: 0; margin-top: 64px; }

    .jstep {
        display: grid; grid-template-columns: 92px 1fr 1fr; gap: 0 44px;
        align-items: center; padding: 0 0 72px; position: relative;
    }
    .jstep:last-of-type { padding-bottom: 0; }

    /* The rail runs down through the numbers and stops at the last one. */
    .jstep:not(:last-of-type)::before {
        content: ''; position: absolute; left: 35px; top: 78px; bottom: -6px;
        width: 2px; background: repeating-linear-gradient(
            to bottom, var(--lemon-deep) 0 7px, transparent 7px 15px);
    }

    .jnum {
        width: 72px; height: 72px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-family: var(--display); font-size: 25px; font-weight: 800;
        color: #fff; background: var(--brand);
        box-shadow: 0 0 0 7px var(--brand-light);
        align-self: start; position: relative; z-index: 1;
    }

    .jbody h3 {
        font-family: var(--display); font-size: clamp(21px, 2.3vw, 26px);
        font-weight: 700; letter-spacing: -.015em; color: var(--ink); margin: 0 0 12px; text-wrap: balance;
    }
    .jbody p { font-size: 15.5px; color: var(--muted); line-height: 1.75; margin: 0; }
    .jmeta {
        margin-top: 16px; display: inline-flex; align-items: center; gap: 7px;
        font-size: 12.5px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase;
        color: var(--lemon-ink); background: var(--lemon-soft);
        border: 1px solid var(--lemon-deep); border-radius: 999px; padding: 6px 13px;
    }

    .jshot { position: relative; isolation: isolate; }
    .jshot img {
        display: block; width: 100%; height: 232px; object-fit: cover;
        border-radius: var(--r-lg); box-shadow: var(--shadow); position: relative; z-index: 1;
    }
    .jshot::before {
        content: ''; position: absolute; z-index: 0; inset: 18px -18px -18px 18px;
        border-radius: var(--r-lg); background: var(--lemon); opacity: .45;
    }
    /* Even steps put the photograph on the inside, so the eye zig-zags down the
       rail instead of running along one straight edge of images. */
    .jstep:nth-child(even) .jbody { grid-column: 3; grid-row: 1; }
    .jstep:nth-child(even) .jshot { grid-column: 2; grid-row: 1; }
    .jstep:nth-child(even) .jshot::before { inset: 18px 18px -18px -18px; }

    /* ---- The optional add-on, deliberately not numbered ---- */
    .addon {
        display: grid; grid-template-columns: 56px 1fr; gap: 22px; align-items: start;
        margin-top: 64px; padding: 30px 32px;
        background: var(--lemon-soft); border: 1px solid var(--lemon-deep);
        border-radius: var(--r-lg);
    }
    .addon-ic {
        width: 56px; height: 56px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: #fff; color: var(--brand-dark); border: 1px solid var(--lemon-deep);
    }
    .addon h3 {
        font-family: var(--display); font-size: 19px; font-weight: 700;
        color: var(--ink); margin: 0 0 8px;
    }
    .addon p { font-size: 15px; color: var(--ink-soft); line-height: 1.72; margin: 0; }

    /* ---- FAQ ---- */
    .faq-section { background: var(--bg); }
    .faq-list {
        margin-top: 40px; display: flex; flex-direction: column; gap: 10px;
    }
    .faq-item {
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--radius); overflow: hidden; transition: border-color .18s, box-shadow .18s;
    }
    .faq-item:has(.faq-q.open) { border-color: var(--lemon-deep); box-shadow: var(--shadow-sm); }
    .faq-q {
        width: 100%; text-align: left; background: none; border: none; padding: 20px 22px;
        font-family: var(--display); font-size: 16px; font-weight: 600; color: var(--ink);
        cursor: pointer; display: flex; justify-content: space-between; align-items: center;
        gap: 18px; line-height: 1.45;
    }
    .faq-q:hover { background: var(--lemon-soft); }
    .faq-q .arrow {
        flex-shrink: 0; color: var(--brand-dark); display: inline-flex;
        transition: transform .22s ease;
    }
    .faq-q.open .arrow { transform: rotate(180deg); }
    .faq-a {
        padding: 0 22px 20px; font-size: 15px; color: var(--muted);
        line-height: 1.75; display: none;
    }
    .faq-a.open { display: block; }

    @media (max-width: 860px) {
        .jstep {
            grid-template-columns: 56px 1fr; gap: 0 20px;
            padding-bottom: 52px;
        }
        .jstep:not(:last-of-type)::before { left: 27px; top: 62px; }
        .jnum { width: 56px; height: 56px; font-size: 20px; box-shadow: 0 0 0 5px var(--brand-light); }
        /* One column of content beside the rail on phones: body, then photo,
           for every step — the desktop zig-zag has nowhere to go here. */
        .jstep:nth-child(even) .jbody,
        .jstep .jbody { grid-column: 2; grid-row: 1; }
        .jstep:nth-child(even) .jshot,
        .jstep .jshot { grid-column: 2; grid-row: 2; margin-top: 22px; }
        .jshot img { height: 190px; }
        .jshot::before,
        .jstep:nth-child(even) .jshot::before { inset: 14px -12px -14px 12px; }
        .addon { grid-template-columns: 1fr; gap: 16px; padding: 26px 22px; }
    }
</style>
@endpush

@section('content')

<section class="page-hero">
    <h1>How It Works</h1>
    <p>Get your documents notarized in four simple steps — entirely online, entirely secure.</p>
</section>

<section class="section">
    <div class="container" style="max-width:940px;">
        <div class="center">
            <div class="section-label">The Process</div>
            <h2 class="section-title">From upload to sealed, in four steps</h2>
            <p class="section-sub">Every step happens in your browser. The only thing you need is a device with a camera and a valid means of identification.</p>
        </div>

        <div class="journey">
            <div class="jstep" data-reveal>
                <div class="jnum">1</div>
                <div class="jbody">
                    <h3>Create your account and upload</h3>
                    <p>Sign up, then fill in one short form: your details, the document you need notarized, and a valid means of identification. Uploads are encrypted in transit and stored where only you and your notary can reach them. Tell us there what the document is for — it is how the right service and price are applied.</p>
                </div>
                <div class="jshot">
                    {{-- Illustrative stock photography. See public/images/site/CREDITS.md. --}}
                    <img src="{{ asset('images/site/step-upload-documents.webp') }}"
                         width="900" height="600" loading="lazy" decoding="async"
                         alt="A desk seen from above with documents and an open laptop.">
                </div>
            </div>

            <div class="jstep" data-reveal>
                <div class="jnum">2</div>
                <div class="jbody">
                    <h3>Choose your notary and pay</h3>
                    <p>Browse the notaries listed on the platform and pick the one you want, with their price shown before you commit. Pay by card or bank transfer to confirm the request. Your notary is notified the moment payment clears, and your request moves into their queue.</p>
                    <span class="jmeta">Nothing starts until payment clears</span>
                </div>
                <div class="jshot">
                    <img src="{{ asset('images/site/home-office-remote.webp') }}"
                         width="900" height="1350" loading="lazy" decoding="async"
                         alt="A woman working at a desk in her home office.">
                </div>
            </div>

            <div class="jstep" data-reveal>
                <div class="jnum">3</div>
                <div class="jbody">
                    <h3>Verify your identity on a live call</h3>
                    <p>Your notary accepts the request and a video session is scheduled. On the call they check your identification against the document in front of them and witness your signature. There is no in-person requirement and no office to travel to — a real, licensed notary is simply on the other end of the call.</p>
                </div>
                <div class="jshot">
                    <img src="{{ asset('images/site/step-video-verification.webp') }}"
                         width="900" height="1350" loading="lazy" decoding="async"
                         alt="A woman holding documents during a video call at her desk.">
                </div>
            </div>

            <div class="jstep" data-reveal>
                <div class="jnum">4</div>
                <div class="jbody">
                    <h3>Download your sealed document</h3>
                    <p>Your notary applies their signature, stamp and seal to the document and completes the request. We email you as soon as it is ready, and the finished PDF waits in your dashboard for you to download whenever you need it again.</p>
                </div>
                <div class="jshot">
                    <img src="{{ asset('images/site/step-notary-seal.webp') }}"
                         width="900" height="601" loading="lazy" decoding="async"
                         alt="A notary pressing a stamp onto a printed document.">
                </div>
            </div>
        </div>

        <div class="addon" data-reveal>
            <div class="addon-ic">
                <x-heroicon-o-truck style="width:26px;height:26px;"/>
            </div>
            <div>
                <h3>Optional: a stamped physical copy</h3>
                <p>If you also need the document in hand, tick the physical copy option on the intake form and give us a delivery address. We arrange for a stamped hard copy to be sent to you. This is chosen up front, at the same time you upload — not afterwards.</p>
            </div>
        </div>

        <div style="text-align:center;margin-top:56px;">
            <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Start your notarization</a>
        </div>
    </div>
</section>

{{-- FAQ --}}
<section class="section faq-section">
    <div class="container" style="max-width:800px;">
        <div class="center">
            <div class="section-label">FAQ</div>
            <h2 class="section-title">Frequently asked questions</h2>
        </div>
        <div class="faq-list">
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)">
                    Is online notarization legally valid? <span class="arrow"><x-heroicon-o-chevron-down style="width:18px;height:18px;display:inline-block;vertical-align:middle;"/></span>
                </button>
                <div class="faq-a">
                    Every notary on the platform is a licensed notary public operating under Nigerian law, and the document they produce carries their own official seal and signature. We also record a SHA-256 fingerprint of each sealed file, so there is a tamper-evident record of exactly what was issued. Whether a Nigerian notarization is accepted by a particular foreign authority is a question for that authority — some documents additionally need legalisation or an apostille, and we will tell you if we think yours does.
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)">
                    Do I need to be physically present? <span class="arrow"><x-heroicon-o-chevron-down style="width:18px;height:18px;display:inline-block;vertical-align:middle;"/></span>
                </button>
                <div class="faq-a">
                    No — the entire process is online. You connect with your notary over a secure video call. No travel, no office visit, no queues. You will need a device with a working camera and your identification to hand.
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)">
                    What types of documents can be notarized? <span class="arrow"><x-heroicon-o-chevron-down style="width:18px;height:18px;display:inline-block;vertical-align:middle;"/></span>
                </button>
                <div class="faq-a">
                    Affidavits, property documents, contracts, travel consent letters, academic credentials and immigration documents are the categories our notaries work in most, and statutory declarations, powers of attorney and business agreements fall within them. If you are unsure whether your document qualifies, send it through anyway — the request is reviewed before anything is sealed, and we will come back to you if it belongs in a different category.
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)">
                    How long does it take? <span class="arrow"><x-heroicon-o-chevron-down style="width:18px;height:18px;display:inline-block;vertical-align:middle;"/></span>
                </button>
                <div class="faq-a">
                    Most documents are notarized within 24 hours, and many are completed in as little as 30 minutes. The clock starts when your payment clears, and the actual time depends on your notary's availability when you book.
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)">
                    Can I get a physical (stamped) copy? <span class="arrow"><x-heroicon-o-chevron-down style="width:18px;height:18px;display:inline-block;vertical-align:middle;"/></span>
                </button>
                <div class="faq-a">
                    Yes. When submitting your request you can tick the physical copy option and give a delivery address, and we will arrange for a stamped hard copy to be sent to you. It has to be chosen on the intake form, so decide before you pay.
                </div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)">
                    How do I pay? <span class="arrow"><x-heroicon-o-chevron-down style="width:18px;height:18px;display:inline-block;vertical-align:middle;"/></span>
                </button>
                <div class="faq-a">
                    You pay once, after choosing your notary, by card or bank transfer. The price is the one shown on that notary's listing for the service you selected, and you see it before you commit. Requests are currently billed in Nigerian Naira.
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
        b.setAttribute('aria-expanded', 'false');
    });
    if (!isOpen) {
        btn.classList.add('open');
        answer.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
    }
}
document.querySelectorAll('.faq-q').forEach(b => b.setAttribute('aria-expanded', 'false'));
</script>
@endsection
