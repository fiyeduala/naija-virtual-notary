@extends('layouts.app', ['title' => 'Verification call'])

@push('styles')
<style>:root { --page-w: 1000px; }</style>
@endpush

@push('styles')
<style>
.call-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    gap: 18px;
    align-items: start;
}
@media (max-width: 900px) { .call-grid { grid-template-columns: 1fr; } }

/* ── Video stage ───────────────────────────────────────────────── */
.stage {
    background: #0e1116;
    border: 1px solid #1c222b;
    border-radius: var(--radius-lg);
    overflow: hidden;
}
.stage-screen {
    position: relative;
    aspect-ratio: 16 / 10;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 28px;
    text-align: center;
}
@media (max-width: 560px) { .stage-screen { aspect-ratio: 4 / 3; padding: 24px 18px; } }

.stage-preview { max-width: 380px; }
.stage-preview .icon {
    width: 46px; height: 46px;
    margin: 0 auto 14px;
    border-radius: 50%;
    background: rgba(84,180,53,.14);
    border: 1px solid rgba(84,180,53,.3);
    display: flex; align-items: center; justify-content: center;
    color: #78d44a;
}
.stage-preview h2 { color: #fff; font-size: 15px; margin-bottom: 6px; }
.stage-preview p { color: rgba(255,255,255,.5); font-size: 13px; line-height: 1.6; }
.stage-room {
    display: inline-block;
    margin-top: 14px;
    padding: 5px 12px;
    border-radius: 6px;
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.1);
    font-size: 11.5px;
    color: rgba(255,255,255,.62);
    letter-spacing: .02em;
}
/* A live call fills the stage edge to edge; the preview keeps its padding. */
.stage-screen.is-live { padding: 0; }
#video-mount { position: absolute; inset: 0; }
#video-mount iframe { width: 100%; height: 100%; border: 0; display: block; }
.stage-msg {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 12px; height: 100%; padding: 28px; text-align: center;
    color: rgba(255,255,255,.6); font-size: 13px; line-height: 1.6;
}
.stage-msg button {
    padding: 9px 18px; border-radius: 8px; cursor: pointer;
    border: 1px solid rgba(120,212,74,.45); background: rgba(84,180,53,.16);
    color: #9ae06f; font: inherit; font-weight: 600; font-size: 13px;
}
.stage-msg button:hover { background: rgba(84,180,53,.26); }

.stage-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 11px 16px;
    border-top: 1px solid #1c222b;
    background: #12161d;
    font-size: 12px;
    color: rgba(255,255,255,.5);
}
.stage-bar svg { flex-shrink: 0; color: rgba(255,255,255,.35); }
.stage-bar .bar-note { min-width: 0; }
.live-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #78d44a; flex-shrink: 0;
    box-shadow: 0 0 0 3px rgba(120,212,74,.18);
}

/* ── Stage size controls ───────────────────────────────────────── */
/* Opt-in, both ways: the call starts at its normal size and the notary
   or client chooses to grow it. Nothing here changes the call itself —
   it is the same iframe either way. */
.stage-tools { margin-left: auto; display: flex; gap: 6px; flex-shrink: 0; }
/* An author display rule beats the UA's [hidden], so hidden has to be said
   again here or the buttons show before the script has wired them up. */
.stage-tools[hidden], .stage-tool[hidden] { display: none; }
.stage-tool {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 10px; border-radius: 7px; cursor: pointer;
    border: 1px solid rgba(255,255,255,.14);
    background: rgba(255,255,255,.06);
    color: rgba(255,255,255,.72);
    font: inherit; font-size: 12px; font-weight: 500;
    white-space: nowrap;
    transition: background .15s, color .15s, border-color .15s;
}
.stage-tool:hover { background: rgba(255,255,255,.13); color: #fff; border-color: rgba(255,255,255,.25); }
.stage-tool svg { width: 14px; height: 14px; color: inherit; }
/* One button, two states — the icon flips with aria-pressed so the
   control always shows the way out of wherever you are. */
.stage-tool .tool-out { display: inline-flex; }
.stage-tool .tool-in  { display: none; }
.stage-tool[aria-pressed="true"] .tool-out { display: none; }
.stage-tool[aria-pressed="true"] .tool-in  { display: inline-flex; }
@media (max-width: 560px) {
    .stage-tool .tool-label { display: none; }
    .stage-tool { padding: 6px 9px; }
}

/* Maximized: the stage covers the window without leaving the page, so
   the notary is one click from the verification form again. */
.stage.is-maximized {
    position: fixed;
    inset: 0;
    /* Above the sticky navbar, which sits at 200. */
    z-index: 300;
    border: 0;
    border-radius: 0;
    display: flex;
    flex-direction: column;
}
.stage.is-maximized .stage-screen,
.stage:fullscreen .stage-screen {
    flex: 1 1 auto;
    aspect-ratio: auto;
    min-height: 0;
    padding: 0;
}
.stage:fullscreen { display: flex; flex-direction: column; background: #0e1116; }
body.stage-is-maximized { overflow: hidden; }

/* ── Side panel ────────────────────────────────────────────────── */
.side-card + .side-card { margin-top: 14px; }
.side-card h2 {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--muted);
    font-weight: 600;
    margin-bottom: 12px;
}
.meta-row {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    padding: 7px 0;
    font-size: 13px;
    border-bottom: 1px solid var(--line);
}
.meta-row:last-child { border-bottom: none; padding-bottom: 0; }
.meta-row dt { color: var(--muted); flex-shrink: 0; }
.meta-row dd { font-weight: 500; text-align: right; word-break: break-word; }

/* ── Verification choices ──────────────────────────────────────── */
.choice {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 12px 13px;
    border: 1.5px solid var(--line);
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-weight: 400;
    margin: 0 0 9px;
    transition: border-color .15s, background .15s;
}
.choice:hover { border-color: var(--brand); background: var(--brand-light); }
.choice:has(input:checked) { border-color: var(--brand); background: var(--brand-light); }
.choice input { width: auto; margin-top: 3px; flex-shrink: 0; accent-color: var(--brand); }
.choice .label { font-size: 13px; font-weight: 500; display: block; line-height: 1.4; }
.choice .hint  { font-size: 12px; color: var(--muted); display: block; margin-top: 2px; line-height: 1.45; }

.wait-note {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 13px 14px;
    border-radius: var(--radius-sm);
    background: var(--brand-light);
    border: 1px solid rgba(84,180,53,.3);
    font-size: 13px;
    line-height: 1.55;
}
.wait-note svg { flex-shrink: 0; margin-top: 1px; color: var(--brand-dark); }
</style>
@endpush

@section('content')
@php
    $viewer = auth()->user();

    // notary.requests.show is scoped to the assigned notary, so an admin acting as
    // fallback goes to their own dashboard rather than hitting a 403.
    $isAssignedNotary = $viewer->notaryProfile && $request->notary_id === $viewer->notaryProfile->id;

    if (! $isNotary) {
        $backRoute = route('client.dashboard');
        $backLabel = 'Back to dashboard';
    } elseif ($isAssignedNotary) {
        $backRoute = route('notary.requests.show', $request);
        $backLabel = 'Back to request';
    } else {
        $backRoute = route('dashboard');
        $backLabel = 'Back to dashboard';
    }
@endphp

<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ $backRoute }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            {{ $backLabel }}
        </a>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <h1>Verification call</h1>
            <span class="pill" style="background:rgba(255,255,255,.12); color:#fff;">{{ $request->reference }}</span>
        </div>
        <div class="sub">{{ $session->scheduled_start_at?->format('l, j M Y · g:i A') ?? 'No fixed time — starting on demand' }}</div>
    </div>
</div>

<div class="shell">
    <div class="call-grid">

        {{-- ── Video stage ─────────────────────────────────────── --}}
        @php
            // A live call needs both halves. Anything else is preview mode, and
            // the notary can still verify from the uploaded ID.
            $live = ! empty($credentials['token']) && ! empty($credentials['url']);

            // Matches the reasons DailyVideoProvider hands back. The manual
            // provider sends none, which lands on the default.
            [$previewTitle, $previewNote] = match ($credentials['unavailable'] ?? null) {
                'not_configured' => ['Video not connected yet', $isNotary
                    ? 'Add your Daily.co API key and domain to .env, then run php artisan nvn:video-check. Until then you can still verify from the uploaded ID.'
                    : 'Your notary will verify your identity from the ID you uploaded. Nothing is needed from you here.'],
                'unreachable' => ['Could not reach the video service', $isNotary
                    ? 'Refresh to try again. If it keeps failing, verify from the uploaded ID — that is equally valid, and the reason is in the application log.'
                    : 'Please refresh in a moment. If it still will not connect, your notary can verify from the ID you uploaded instead.'],
                default => ['Video preview mode',
                    'No video provider is connected yet. Once one is configured the live call appears here — the rest of the flow works now.'],
            };
        @endphp
        <div class="stage" id="video-stage">
            <div class="stage-screen @if ($live) is-live @endif">
                @if ($live)
                    {{-- {{ }} escapes the quotes, so the JSON survives the attribute intact. --}}
                    <div id="video-mount" data-credentials="{{ json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}"></div>
                @else
                    <div class="stage-preview">
                        <div class="icon">
                            <x-heroicon-o-video-camera style="width:22px;height:22px;"/>
                        </div>
                        <h2>{{ $previewTitle }}</h2>
                        <p>{{ $previewNote }}</p>
                        <span class="stage-room">Room · {{ $credentials['room'] }}</span>
                    </div>
                @endif
            </div>
            <div class="stage-bar">
                @if ($live)
                    <span class="live-dot"></span>
                @else
                    <x-heroicon-o-lock-closed style="width:13px;height:13px;"/>
                @endif
                <span class="bar-note">This call is for identity verification only and is not recorded.</span>

                @if ($live)
                    {{-- Hidden until the script wires them up: a button that does
                         nothing is worse than no button, and the fullscreen one
                         is removed outright where the browser has no API for it. --}}
                    <div class="stage-tools" id="stage-tools" hidden>
                        <button type="button" class="stage-tool" data-stage-maximize aria-pressed="false">
                            <span class="tool-out"><x-heroicon-o-arrows-pointing-out/></span>
                            <span class="tool-in"><x-heroicon-o-arrows-pointing-in/></span>
                            <span class="tool-label">Maximize</span>
                        </button>
                        <button type="button" class="stage-tool" data-stage-fullscreen aria-pressed="false" hidden>
                            <span class="tool-out"><x-heroicon-o-tv/></span>
                            <span class="tool-in"><x-heroicon-o-x-mark/></span>
                            <span class="tool-label">Full screen</span>
                        </button>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── Side panel ──────────────────────────────────────── --}}
        <div>
            <div class="card side-card" style="padding:18px 20px;">
                <h2>Session details</h2>
                <dl style="margin:0;">
                    <div class="meta-row">
                        <dt>Reference</dt>
                        <dd>{{ $request->reference }}</dd>
                    </div>
                    <div class="meta-row">
                        <dt>{{ $isNotary ? 'Client' : 'Notary' }}</dt>
                        <dd>{{ $isNotary ? $request->client->full_name : ($request->notary?->user?->full_name ?? 'Assigning…') }}</dd>
                    </div>
                    @if ($request->service)
                    <div class="meta-row">
                        <dt>Service</dt>
                        <dd>{{ $request->service->service_type }}</dd>
                    </div>
                    @endif
                    <div class="meta-row">
                        <dt>Scheduled</dt>
                        <dd>{{ $session->scheduled_start_at?->format('j M · g:i A') ?? 'On demand' }}</dd>
                    </div>
                </dl>

                <a class="btn btn-ghost btn-block btn-sm" style="margin-top:14px; justify-content:center;"
                   href="{{ route('messages.show', $request) }}">
                    <x-heroicon-o-chat-bubble-left-right style="width:14px;height:14px;"/>
                    Message {{ $isNotary ? 'the client' : 'your notary' }}
                </a>
            </div>

            @if ($isNotary)
                <form method="POST" action="{{ route('session.verify', $request) }}" class="card side-card" style="padding:18px 20px;">
                    @csrf
                    <h2>Confirm the client's identity</h2>

                    <label class="choice">
                        <input type="radio" name="method" value="live_visual" checked>
                        <span>
                            <span class="label">Verified live on camera</span>
                            <span class="hint">I checked the client's ID during this call.</span>
                        </span>
                    </label>

                    <label class="choice">
                        <input type="radio" name="method" value="uploaded_id">
                        <span>
                            <span class="label">Uploaded ID is sufficient</span>
                            <span class="hint">The document on file meets the requirement.</span>
                        </span>
                    </label>

                    <p class="text-sm muted" style="margin:2px 0 14px; font-size:12px; line-height:1.5;">
                        Either is valid — this is your professional judgement, and it is recorded in the audit trail.
                    </p>

                    <button class="btn btn-block" type="submit">
                        <x-heroicon-o-check-circle style="width:15px;height:15px;"/>
                        Confirm &amp; proceed to notarize
                    </button>
                </form>
            @else
                <div class="card side-card" style="padding:18px 20px;">
                    <h2>What happens next</h2>
                    <div class="wait-note">
                        <x-heroicon-o-identification style="width:17px;height:17px;"/>
                        <span>Please stay on the call while the notary verifies your identity. Have your ID ready to show on camera if asked.</span>
                    </div>
                </div>
            @endif
        </div>

    </div>
</div>
@endsection

@if ($live)
@push('scripts')
{{-- Daily Prebuilt. The URL comes from config so the CDN/version is swappable
     and so no literal @ ends up in this template. --}}
<script crossorigin src="{{ config('video.daily.js_url') }}"></script>
<script>
/*
 * Stage size controls.
 *
 * Deliberately separate from the Daily setup below and running first: if the
 * video library fails to load, the call is dead but nothing here needs to be,
 * and the reverse — a browser with no Fullscreen API — must not stop a call
 * from starting.
 *
 * Maximize is ours and always available: fixed over the page, still the same
 * page, so the notary's verification form is one click away. Full screen is the
 * browser's and only offered where it exists. Daily Prebuilt keeps its own
 * fullscreen button inside the iframe for the video area alone.
 */
(function () {
    var stage = document.getElementById('video-stage');
    var tools = document.getElementById('stage-tools');
    if (!stage || !tools) { return; }

    var maximizeBtn  = tools.querySelector('[data-stage-maximize]');
    var fullscreenBtn = tools.querySelector('[data-stage-fullscreen]');

    function setState(button, on, label) {
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        button.querySelector('.tool-label').textContent = label;
        button.title = label;
    }

    // ── Maximize ─────────────────────────────────────────────────────
    function isMaximized() { return stage.classList.contains('is-maximized'); }

    function setMaximized(on) {
        stage.classList.toggle('is-maximized', on);
        // Without this the page behind keeps scrolling under the overlay.
        document.body.classList.toggle('stage-is-maximized', on);
        setState(maximizeBtn, on, on ? 'Minimize' : 'Maximize');
    }

    maximizeBtn.addEventListener('click', function () { setMaximized(!isMaximized()); });

    // ── Full screen ──────────────────────────────────────────────────
    var canFullscreen = !!(stage.requestFullscreen || stage.webkitRequestFullscreen);

    function fullscreenElement() {
        return document.fullscreenElement || document.webkitFullscreenElement || null;
    }

    if (canFullscreen) {
        fullscreenBtn.hidden = false;

        fullscreenBtn.addEventListener('click', function () {
            if (fullscreenElement() === stage) {
                (document.exitFullscreen || document.webkitExitFullscreen).call(document);

                return;
            }

            // Two overlays at once is one too many — going full screen from a
            // maximized stage drops the maximized state on the way in.
            if (isMaximized()) { setMaximized(false); }

            var request = stage.requestFullscreen || stage.webkitRequestFullscreen;
            var result = request.call(stage);

            // Safari's older form returns nothing; only a real promise can reject.
            if (result && typeof result.catch === 'function') {
                result.catch(function () { setMaximized(true); });
            }
        });

        // The browser can leave fullscreen without us — Escape, the OS, another
        // element taking it — so the label follows the document, not the click.
        ['fullscreenchange', 'webkitfullscreenchange'].forEach(function (event) {
            document.addEventListener(event, function () {
                setState(fullscreenBtn, fullscreenElement() === stage,
                    fullscreenElement() === stage ? 'Exit full screen' : 'Full screen');
            });
        });
    }

    // Escape gets you out of the maximized stage too, because every other
    // full-window thing on the web works that way. Fullscreen is the browser's
    // to close and is left alone here.
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isMaximized() && !fullscreenElement()) {
            setMaximized(false);
        }
    });

    // Sets the tooltips as well as the labels, so a button that is only an icon
    // on a narrow screen still says what it does.
    setState(maximizeBtn, false, 'Maximize');
    if (canFullscreen) { setState(fullscreenBtn, false, 'Full screen'); }

    tools.hidden = false;
})();
</script>
<script>
(function () {
    var mount = document.getElementById('video-mount');
    if (!mount) { return; }

    function message(text, buttonLabel, onClick) {
        mount.innerHTML = '';
        var box = document.createElement('div');
        box.className = 'stage-msg';
        var p = document.createElement('p');
        p.textContent = text;
        box.appendChild(p);
        if (buttonLabel) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = buttonLabel;
            button.addEventListener('click', onClick);
            box.appendChild(button);
        }
        mount.appendChild(box);
    }

    var credentials;
    try {
        credentials = JSON.parse(mount.dataset.credentials);
    } catch (e) {
        message('The call could not be set up. Please refresh the page.');
        return;
    }

    if (typeof window.DailyIframe === 'undefined') {
        message('The video library could not be loaded. Check your connection and refresh.',
            'Reload', function () { window.location.reload(); });
        return;
    }

    var frame = window.DailyIframe.createFrame(mount, {
        showLeaveButton: true,
        showFullscreenButton: true,
        iframeStyle: { width: '100%', height: '100%', border: '0' }
    });

    // Rejoining needs a fresh meeting token, so the page is reloaded rather
    // than the old one replayed.
    frame.on('left-meeting', function () {
        frame.destroy();
        message('You have left the call.', 'Rejoin', function () { window.location.reload(); });
    });

    frame.on('error', function (event) {
        frame.destroy();
        message((event && event.errorMsg) ? event.errorMsg : 'The call ended unexpectedly.',
            'Try again', function () { window.location.reload(); });
    });

    frame.join({
        url: credentials.url,
        token: credentials.token,
        userName: credentials.userName
    });
})();
</script>
@endpush
@endif
