@extends('layouts.app', ['title' => 'How this works'])

{{--
    The walkthrough. Every screenshot on this page is this software, captured
    against a local server with invented people and invented documents — there
    is no real client's name or paperwork anywhere on it, and there must never
    be. See database/seeders/GuideDemoSeeder.php, which builds the scene the
    screenshots were taken of.

    The prose deliberately does not restate what the screens say. It explains
    the things a screenshot cannot: why there is a listing review at all, why
    the client's signature is kept out of your seal, what happens to a job you
    leave sitting. Anything a notary can read off a button is left to the
    button.
--}}

@push('styles')
<style>
    .shell { padding-top: 28px; }

    /* ── Contents ── */
    .g-toc {
        display: flex; flex-wrap: wrap; gap: 7px;
        padding: 16px 18px; margin-bottom: 26px;
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--radius); box-shadow: var(--shadow-sm);
    }
    .g-toc-label {
        width: 100%; font-size: 11px; font-weight: 700; letter-spacing: .07em;
        text-transform: uppercase; color: var(--muted); margin-bottom: 4px;
    }
    .g-toc a {
        font-size: 12.5px; font-weight: 600; color: var(--muted);
        text-decoration: none; padding: 5px 11px;
        border: 1px solid var(--line); border-radius: 999px;
        transition: all .15s;
    }
    .g-toc a:hover { color: var(--brand-dark); background: var(--brand-light); border-color: var(--brand); }

    /* ── Live checklist ── */
    .g-check { padding: 0; overflow: hidden; margin-bottom: 34px; }
    .g-check-hd {
        padding: 18px 22px 16px;
        border-bottom: 1px solid var(--line);
        background: linear-gradient(135deg, #f4fbef 0%, #fbfdf9 100%);
    }
    .g-check-hd h2 { font-size: 15px; font-weight: 700; color: var(--ink); margin: 0 0 3px; }
    .g-check-hd p  { font-size: 12.5px; color: var(--muted); margin: 0; line-height: 1.6; }
    .g-check-row {
        display: flex; align-items: center; gap: 13px;
        padding: 13px 22px; border-bottom: 1px solid var(--line);
        font-size: 13.5px; color: var(--ink); text-decoration: none;
        transition: background .15s;
    }
    .g-check-row:last-child { border-bottom: 0; }
    .g-check-row:hover { background: #fafbfc; color: var(--ink); }
    .g-tick {
        flex: 0 0 20px; width: 20px; height: 20px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        border: 1.5px solid var(--line); background: #fff;
    }
    .g-check-row.is-done .g-tick { background: var(--brand); border-color: var(--brand); }
    .g-check-row.is-done span.l { color: var(--muted); }
    .g-check-row span.l { flex: 1; font-weight: 600; }
    .g-check-row span.a { font-size: 12px; font-weight: 600; color: var(--brand-dark); }
    .g-check-row.is-done span.a { color: var(--muted); font-weight: 500; }

    /* ── Sections ── */
    .g-sec { margin-bottom: 44px; scroll-margin-top: 20px; }
    .g-sec-hd { display: flex; align-items: baseline; gap: 12px; margin-bottom: 12px; }
    .g-num {
        flex: 0 0 auto; font-size: 11px; font-weight: 700;
        letter-spacing: .06em; color: var(--brand-dark);
        background: var(--brand-light); border-radius: 999px;
        padding: 4px 10px; position: relative; top: -2px;
        white-space: nowrap;
    }
    .g-sec h2 { font-size: 19px; font-weight: 800; color: var(--ink); margin: 0; line-height: 1.3; }
    .g-sec p { font-size: 14px; color: #3f4a57; line-height: 1.75; margin: 0 0 13px; max-width: 68ch; }
    .g-sec p:last-child { margin-bottom: 0; }
    .g-sec strong { color: var(--ink); font-weight: 600; }
    .g-sec ul { margin: 0 0 13px; padding-left: 20px; max-width: 68ch; }
    .g-sec li { font-size: 14px; color: #3f4a57; line-height: 1.7; margin-bottom: 7px; }

    /* ── A screenshot ── */
    .g-fig { margin: 18px 0; }
    .g-fig img {
        display: block; width: 100%; height: auto;
        border: 1px solid var(--line); border-radius: var(--radius);
        box-shadow: var(--shadow); background: #fff;
    }
    .g-fig figcaption {
        font-size: 12px; color: var(--muted); line-height: 1.6;
        margin-top: 9px; padding-left: 2px;
    }

    /* ── Asides ── */
    .g-note {
        padding: 13px 16px; border-radius: var(--radius-sm);
        border: 1px solid var(--line); background: #fafbfc;
        font-size: 13px; color: #3f4a57; line-height: 1.7;
        margin: 0 0 13px; max-width: 68ch;
    }
    .g-note.warn { background: var(--warning-bg); border-color: #efd9ab; color: #6b4700; }
    .g-note b { color: inherit; font-weight: 700; }

    .g-do {
        display: inline-flex; align-items: center; gap: 6px;
        font-size: 13px; font-weight: 600; color: var(--brand-dark);
        text-decoration: none; padding: 8px 15px;
        border: 1.5px solid var(--line); border-radius: var(--radius-sm);
        transition: all .15s;
    }
    .g-do:hover { background: var(--brand-light); border-color: var(--brand); color: var(--brand-dark); }

    .g-end {
        margin-top: 10px; padding: 24px 26px;
        border-radius: var(--radius); border: 1px solid var(--line);
        background: linear-gradient(135deg, #f4fbef 0%, #fbfdf9 100%);
    }
    .g-end h2 { font-size: 16px; font-weight: 800; color: var(--ink); margin: 0 0 6px; }
    .g-end p  { font-size: 13.5px; color: var(--muted); line-height: 1.7; margin: 0 0 14px; max-width: 64ch; }

    @media (max-width: 640px) {
        .g-sec h2 { font-size: 17px; }
        .g-sec-hd { flex-direction: column; gap: 7px; align-items: flex-start; }
        .g-check-row { padding: 12px 16px; }
        .g-check-hd { padding: 16px; }
    }
</style>
@endpush

@section('content')

<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ route('notary.dashboard') }}" class="page-back">← Back to dashboard</a>
        <h1>How this works</h1>
        <div class="sub">Every screen you will use, in the order you will meet it.</div>
    </div>
</div>

<div class="shell">

    <nav class="g-toc">
        <div class="g-toc-label">On this page</div>
        <a href="#fee">Membership fee</a>
        <a href="#assets">Signature, stamp &amp; seal</a>
        <a href="#bank">Payout account</a>
        <a href="#prices">Services &amp; prices</a>
        <a href="#listing">Being listed</a>
        <a href="#job">A job arrives</a>
        <a href="#category">Wrong category</a>
        <a href="#call">The video call</a>
        <a href="#seal">Sealing the document</a>
        <a href="#after">Afterwards</a>
        <a href="#money">Getting paid</a>
        <a href="#offsite">Your own work</a>
        <a href="#renew">Renewing</a>
    </nav>

    @if (! empty($steps))
    {{-- Not a tutorial step: the notary's real state, read off their own
         profile through the same methods the dashboard and the listing review
         read. A guide that tells someone to do what they did last week is
         ignored, so this says which of it is already behind them. --}}
    @php $left = collect($steps)->reject(fn ($s) => $s['done'])->count(); @endphp
    <div class="card g-check">
        <div class="g-check-hd">
            <h2>{{ $left === 0 ? 'You are all set up' : 'Where you are right now' }}</h2>
            <p>
                @if ($left === 0)
                    Everything on the setup list is behind you. The rest of this page is about the work itself.
                @else
                    {{ $left }} {{ \Illuminate\Support\Str::plural('thing', $left) }} still to do before a client can book you. Each one links to the screen that does it.
                @endif
            </p>
        </div>
        @foreach ($steps as $step)
        <a href="{{ $step['route'] }}" class="g-check-row {{ $step['done'] ? 'is-done' : '' }}">
            <span class="g-tick">
                @if ($step['done'])
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                @endif
            </span>
            <span class="l">{{ $step['label'] }}</span>
            <span class="a">{{ $step['done'] ? 'Done' : 'Open →' }}</span>
        </a>
        @endforeach
    </div>
    @endif

    {{-- ─────────────────────────── Setting up ─────────────────────────── --}}

    <section class="g-sec" id="start">
        <div class="g-sec-hd">
            <span class="g-num">Start</span>
            <h2>Your dashboard</h2>
        </div>
        <p>
            Your dashboard is where every job begins and ends. The band across the
            top counts what has come in and what you have finished; below it sits a
            coloured banner that always tells you the one thing standing between you
            and being bookable. Work that banner until it turns green and you are
            live — there is nothing else to find.
        </p>
        @include('notary.partials.guide-figure', ['shot' => 'dashboard', 'caption' => 'The notary dashboard. The banner under the stats names the next thing to do; the cards below it are the jobs waiting on you.'])
        <p>
            Nothing on this page needs a refresh to be correct. If a client pays
            while you are looking at it, the job appears the next time you load it.
        </p>
    </section>

    <section class="g-sec" id="fee">
        <div class="g-sec-hd">
            <span class="g-num">Step 1</span>
            <h2>The membership fee</h2>
        </div>
        <p>
            Being a partner here costs <strong>{{ $feeDisplay }} a year</strong>. It is what
            puts you in the marketplace where clients choose a notary, and it is paid
            once a year rather than per job — we take our share out of what each
            client pays instead, which is covered further down.
        </p>
        @include('notary.partials.guide-figure', ['shot' => 'fee', 'caption' => 'The fee screen. Paying opens the profile screens; until it is paid they stay closed.'])
        <div class="g-note">
            Payment goes through Paystack — card, transfer or USSD. You come straight
            back here afterwards and the screen confirms it. If your bank takes a
            moment, the confirmation can land a minute or two later; it will not need
            paying twice.
        </div>
        <a href="{{ route('notary.onboarding.fee') }}" class="g-do">Open the fee screen →</a>
    </section>

    <section class="g-sec" id="assets">
        <div class="g-sec-hd">
            <span class="g-num">Step 2</span>
            <h2>Your signature, stamp and seal</h2>
        </div>
        <p>
            Three images, and they are the whole job: your <strong>signature</strong>, your
            <strong>stamp</strong> and your <strong>seal</strong>. Every document you finish is
            marked with these and nothing else, so upload the real ones — the same
            marks you would put on paper in your chambers.
        </p>
        <ul>
            <li>PNG or JPG. A PNG with a transparent background sits on the page far more cleanly than a white rectangle over the text.</li>
            <li>Sign on white paper, photograph it straight-on in daylight, and crop tight. A phone is entirely good enough.</li>
            <li>Your <strong>initials</strong> and your <strong>Supreme Court number</strong> go in the same form. Initials are what you use to mark each page of a long document; they are not part of the seal.</li>
        </ul>
        @include('notary.partials.guide-figure', ['shot' => 'assets', 'caption' => 'The e-signature and identity form. All three files are required — one missing mark is enough to stop a document being finished.'])
        <div class="g-note warn">
            <b>All three or none.</b> A profile holding a signature and a stamp but no
            seal cannot finish a document, and the editor will say so in the middle of
            a job rather than before it. Upload the set in one sitting.
        </div>
        @if ($assetGuide !== '')
        <p>
            If you do not yet have these as files,
            <a href="{{ $assetGuide }}" style="color:var(--brand-dark);font-weight:600;">read how to get them made</a>.
        </p>
        @endif
        <a href="{{ route('notary.profile.edit') }}" class="g-do">Upload your marks →</a>
    </section>

    <section class="g-sec" id="bank">
        <div class="g-sec-hd">
            <span class="g-num">Step 3</span>
            <h2>Where your money goes</h2>
        </div>
        <p>
            Pick your bank from the list and type your account number; we read the
            account name back from the bank so you can see it is the right one.
            <strong>Pick the bank from the list rather than typing its name</strong> — a typed
            name is only text, and nothing can be sent to it.
        </p>
        @include('notary.partials.guide-figure', ['shot' => 'bank', 'caption' => 'The payout account form. The bank is a choice, not a free-text field, and that is deliberate.'])
        <p>
            This is the only place your account details are used. Clients never see
            them, and they are not shown on your public profile.
        </p>
    </section>

    <section class="g-sec" id="prices">
        <div class="g-sec-hd">
            <span class="g-num">Step 4</span>
            <h2>Your services and your prices</h2>
        </div>
        <p>
            A service is one kind of work at one price — an affidavit, a deed, a power
            of attorney. You set the figure yourself. Add as many as you like, but add
            at least one: a notary with no priced service cannot be booked, because
            there is nothing for a client to pay for.
        </p>
        <ul>
            <li><strong>Price</strong> in naira. Leave the dollar field alone unless you have been told otherwise.</li>
            <li><strong>Duration</strong> is how long you expect to need on the call. It sets the size of the slots clients can book, so be honest with it rather than optimistic.</li>
            <li><strong>Description</strong> is read by the client while they are choosing. Say what is included and what you will need them to have ready.</li>
        </ul>
        @include('notary.partials.guide-figure', ['shot' => 'service', 'caption' => 'Adding a service. The list of what you already offer sits directly underneath, where you can change or remove any of it.'])
        <p>
            You can change a price whenever you want. A change never touches a job
            already booked — whatever the client was quoted is what they pay, and that
            is the figure you earn from.
        </p>
    </section>

    <section class="g-sec" id="listing">
        <div class="g-sec-hd">
            <span class="g-num">Step 5</span>
            <h2>Asking to be listed</h2>
        </div>
        <p>
            With the fee paid, the three marks uploaded, a payout account saved and a
            service priced, a button appears at the bottom of your profile:
            <strong>send my profile for review</strong>. Pressing it does not put you in the
            marketplace. It puts you in front of a person.
        </p>
        <p>
            Somebody here opens your signature, your stamp and your seal and looks at
            them. That usually takes about a day. We do it because a client picking a
            notary off a list has no way to check any of this themselves, and a blurry
            or wrong seal found on the day of a job is a ruined job.
        </p>
        @include('notary.partials.guide-figure', ['shot' => 'golive', 'caption' => 'The profile with everything in place, and the request-for-review button at the foot of it.'])
        <div class="g-note">
            If something needs changing you are told what, in plain words, on your own
            dashboard — not in an email you might miss. Fix it and send it again;
            there is no limit on tries and nothing is held against you.
        </div>
    </section>

    {{-- ─────────────────────────── The work ─────────────────────────── --}}

    <section class="g-sec" id="job">
        <div class="g-sec-hd">
            <span class="g-num">The work</span>
            <h2>A job arrives</h2>
        </div>
        <p>
            A client chooses you, uploads their document and pays. Only then does it
            reach you — <strong>nothing on your desk is unpaid</strong>. It appears on your
            dashboard and under <strong>Requests</strong>, oldest first, because the oldest is
            the one with a client wondering.
        </p>
        @include('notary.partials.guide-figure', ['shot' => 'incoming', 'caption' => 'Incoming requests. Each row carries its reference, the service booked and how long it has been waiting.'])
        <p>
            Open one and you get the details, the documents and a message thread with
            the client. Read the document <em>before</em> you accept. Then either:
        </p>
        <ul>
            <li><strong>Accept</strong> — the job is yours, and the client is told so.</li>
            <li><strong>Decline</strong> — say why, briefly. The client is freed to book somebody else. Declining is not a mark against you; sitting on something you were never going to do is far worse.</li>
        </ul>
        @include('notary.partials.guide-figure', ['shot' => 'request', 'caption' => 'A single request: the documents, the thread, and the accept-or-decline decision.'])
        <div class="g-note warn">
            <b>A job left sitting does not stay yours.</b> After a while an unanswered
            request can be taken over by the platform's own notary so the client is not
            left waiting. The fee follows whoever actually seals the document. Answer
            the ones you mean to do and decline the ones you do not.
        </div>
    </section>

    <section class="g-sec" id="category">
        <div class="g-sec-hd">
            <span class="g-num">When it is wrong</span>
            <h2>The client booked the wrong thing</h2>
        </div>
        <p>
            It happens often: somebody books an affidavit and uploads a deed. You do
            not have to decline and start again. On the request screen,
            <strong>wrong category?</strong> lets you tell the client which service this
            actually is.
        </p>
        <p>
            They see your note and the new price. If the right service costs more they
            are asked only for the difference. Either way you are not deciding on
            their behalf — they agree to it before anything changes.
        </p>
        @include('notary.partials.guide-figure', ['shot' => 'category-query', 'caption' => 'Querying the category. You name the service it should have been; the client approves the difference.'])
        <p>
            If the document is not something you do at all, withdraw the query and
            decline the job instead.
        </p>
    </section>

    <section class="g-sec" id="call">
        <div class="g-sec-hd">
            <span class="g-num">The call</span>
            <h2>The video call</h2>
        </div>
        <p>
            This is the part that makes it a notarization rather than a stamp. You meet
            the client on video, you satisfy yourself that they are who they say they
            are, and you watch them acknowledge the document. Everything after this is
            paperwork.
        </p>
        <p>
            The call opens in the page — no app to install, nothing for the client to
            download. What to do on it:
        </p>
        <ul>
            <li>Have them hold their ID up to the camera, and compare the face in front of you with the face on it.</li>
            <li>Ask them to confirm, out loud, that the document is theirs and that they are signing it freely.</li>
            <li>Tick the identity confirmation on the page only once you actually believe it. Ticking it is your attestation, not a formality.</li>
        </ul>
        @include('notary.partials.guide-figure', ['shot' => 'join', 'caption' => 'The call screen: the session details, the identity confirmation, and what happens next.'])
        <div class="g-note">
            Calls are not recorded. What is kept is the record that the call happened,
            who was on it and when — which is what the certificate rests on.
        </div>
    </section>

    <section class="g-sec" id="seal">
        <div class="g-sec-hd">
            <span class="g-num">Sealing</span>
            <h2>Placing your marks and sealing</h2>
        </div>
        <p>
            The editor shows the document as the client sent it. Down the side are your
            marks. <strong>Click a tool, then click the page where it goes.</strong> Drag a
            corner to resize, drag the middle to move it, double-click to remove it.
        </p>
        <ul>
            <li><strong>Initials</strong> on each page of anything long.</li>
            <li><strong>Signature</strong>, <strong>stamp</strong> and <strong>seal</strong> where the certificate belongs — usually the last page, but put them where the document calls for them.</li>
            <li><strong>Text</strong> and <strong>date</strong> for anything that has to be written in by hand.</li>
        </ul>
        @include('notary.partials.guide-figure', ['shot' => 'notarize', 'caption' => 'The editor. Tools on the left, the document in the middle, and a tab for each file when a job has more than one.'])
        <p>
            If the job came with several documents, there is a tab for each and a flag
            on any you have not marked yet. <strong>Save placements</strong> as you go — it
            keeps your work without finishing the job, so you can leave and come back.
        </p>
        <div class="g-note warn">
            <b>The client's own signature is a separate tool, and it is separate on
            purpose.</b> When a client has uploaded their signature and asked you to
            place it, use that set — never your own signature standing in for theirs.
            It is recorded as your act of placing their mark, and it is kept out of
            your seal, which is what keeps the question of who signed what answerable
            later.
        </div>
        <p>
            <strong>Finalize &amp; seal</strong> is the end. It burns the marks into the PDF,
            writes the certificate, and sends the sealed copy to the client. After that
            the document cannot be edited — so read the page once more before you press
            it.
        </p>
    </section>

    <section class="g-sec" id="after">
        <div class="g-sec-hd">
            <span class="g-num">Afterwards</span>
            <h2>What the client gets, and what you keep</h2>
        </div>
        <p>
            The client is sent the sealed document and a verification link. Anyone the
            document is shown to — a bank, an embassy, a court — can follow that link
            and see that it was notarized here, by you, on that date. That is the point
            of the whole exercise.
        </p>
        <p>
            Your side of it sits under <strong>Requests → Completed</strong>: every job you
            have finished, with the sealed copy still downloadable. Clients do ask again
            months later, and it will be there.
        </p>
        @include('notary.partials.guide-figure', ['shot' => 'completed', 'caption' => 'Completed work. The sealed copy stays available from here.'])
    </section>

    <section class="g-sec" id="money">
        <div class="g-sec-hd">
            <span class="g-num">Money</span>
            <h2>Getting paid</h2>
        </div>
        <p>
            You earn when a job is <strong>completed</strong>, not when it is paid for. The
            platform keeps <strong>{{ $rate }}%</strong> of what the client paid and the rest is
            yours.
        </p>
        <p>
            The earnings block on your dashboard splits it three ways:
            <strong>your share</strong> of everything you have finished,
            <strong>paid out</strong> — what has already reached your account, and
            <strong>awaiting payout</strong> — what is owed and has not gone out yet. Payouts
            are sent to the account you saved; you do not have to request one.
        </p>
        @include('notary.partials.guide-figure', ['shot' => 'earnings', 'caption' => 'The earnings block: your share, what has been paid out, and what is still owed.'])
        <div class="g-note">
            A job a client paid for in a foreign currency is shown separately and
            counted apart from what can be paid out, because a payout settles in naira.
            If you see such a line, that is what it is.
        </div>
    </section>

    <section class="g-sec" id="offsite">
        <div class="g-sec-hd">
            <span class="g-num">Your own work</span>
            <h2>Documents you took on yourself</h2>
        </div>
        <p>
            A client who walks into your chambers is your client, not ours. But you may
            still want the document sealed here, so it carries a verification link like
            everything else. That is what <strong>offsite notarization</strong> is for.
        </p>
        <p>
            You upload the document, you record what the client paid you — which is
            yours and does not pass through us — and you pay
            <strong>{{ $offsiteFee }} per document</strong> for the sealing. Then you are in the
            same editor, with the same marks, and the client gets the same verification
            link.
        </p>
        @include('notary.partials.guide-figure', ['shot' => 'offsite', 'caption' => 'Offsite work. What the client paid you is recorded for your own books; the per-document fee is what you pay us.'])
        <div class="g-note">
            There is no video call on an offsite job, because you already met the
            person. The identity check is yours, made in the room, and the certificate
            says so.
        </div>
        <a href="{{ route('notary.offsite.index') }}" class="g-do">Open offsite notarization →</a>
    </section>

    <section class="g-sec" id="renew">
        <div class="g-sec-hd">
            <span class="g-num">Each year</span>
            <h2>Keeping your listing</h2>
        </div>
        <p>
            Your membership runs a year from the day you paid. {{ $reviewDays }} days
            before it ends your dashboard starts saying so, and when it does lapse you
            are not removed, locked out or deleted — you simply stop appearing in the
            marketplace until you pay again. Your profile, your marks, your completed
            work and anything still owed to you all stay exactly where they are.
        </p>
        <div class="g-note">
            A lapse never strands a client. Work already booked is yours to finish and
            yours to be paid for, whatever your membership says that week.
        </div>
    </section>

    <div class="g-end">
        <h2>Something here not matching what you see?</h2>
        <p>
            Then tell us, because one of the two is wrong and it may well be this page.
            Message us from your dashboard; we would rather fix a confusing screen than
            keep explaining it.
        </p>
        <a href="{{ route('notary.dashboard') }}" class="btn">Back to my dashboard</a>
    </div>

</div>

@endsection
