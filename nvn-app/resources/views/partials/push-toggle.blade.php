{{--
    The "Alerts" bell and the desktop "Install" button.

    Lives in a partial because admins work in the Filament panel, which does not
    use layouts/app.blade.php — and an admin who can never reach the opt-in is
    exactly how this feature managed to notify nobody for months.

    $variant: 'nav' for the dark app navbar, 'panel' for the Filament topbar.
--}}
@auth
@php($variant = $variant ?? 'nav')

<style>
    .nvn-app-tools { display: inline-flex; align-items: center; gap: 6px; }
    .btn-push {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px 10px; border-radius: 8px; cursor: pointer;
        font-size: 13px; font-weight: 600; line-height: 1;
        border: 1px solid rgba(255,255,255,.16);
        background: rgba(255,255,255,.06);
        color: rgba(255,255,255,.82);
    }
    .btn-push[hidden] { display: none; }
    .btn-push:hover { color: #fff; border-color: rgba(255,255,255,.3); }
    .btn-push svg { width: 15px; height: 15px; }
    .btn-push .bell-off { display: none; }
    .btn-push[aria-pressed="true"] { color: #78d44a; border-color: rgba(120,212,74,.4); }
    .btn-push[aria-pressed="true"] .bell-on  { display: none; }
    .btn-push[aria-pressed="true"] .bell-off { display: inline-flex; }
    .btn-push:disabled { opacity: .55; cursor: default; }

    /* Filament's topbar is light by default and dark under html.dark. */
    .btn-push--panel { color: #4b5563; border-color: rgba(0,0,0,.14); background: rgba(0,0,0,.03); }
    .btn-push--panel:hover { color: #111827; border-color: rgba(0,0,0,.25); }
    .dark .btn-push--panel { color: #d1d5db; border-color: rgba(255,255,255,.16); background: rgba(255,255,255,.06); }
    .dark .btn-push--panel:hover { color: #fff; }

    @media (max-width: 640px) { .btn-push .push-label { display: none; } .btn-push { padding: 6px 8px; } }
</style>

<span class="nvn-app-tools">
    <button type="button" id="push-toggle" class="btn-push btn-push--{{ $variant }}" aria-pressed="false" hidden>
        <span class="bell-on">@svg('heroicon-o-bell')</span>
        <span class="bell-off">@svg('heroicon-s-bell-alert')</span>
        <span class="push-label">Alerts</span>
    </button>

    <button type="button" id="install-app" class="btn-push btn-push--{{ $variant }}" hidden>
        @svg('heroicon-o-arrow-down-tray')
        <span class="push-label">Install app</span>
    </button>
</span>

<script>
(function () {
    const btn = document.getElementById('push-toggle');
    if (!btn || btn.dataset.wired) return;
    btn.dataset.wired = '1';

    const vapidKey = @json(trim((string) config('nvn.vapid_public_key')));
    const label    = btn.querySelector('.push-label');
    const csrf     = @json(csrf_token());

    /* Only an admin can act on "the server has no signing keys", and only an
       admin should be shown it. Everyone else just gets no bell. */
    const isAdmin  = @json((bool) auth()->user()->isAdmin());

    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    /* Registered here as well as in the layout: an admin may only ever load the
       Filament panel, and without a registration serviceWorker.ready never
       resolves, so the bell would sit there doing nothing. */
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    }

    /* iOS delivers Web Push only to a Home Screen install, never to a Safari
       tab. Offer the instruction rather than a switch that cannot work. */
    const isIOS      = /iPad|iPhone|iPod/.test(navigator.userAgent)
                    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const standalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true;

    /* ---- Install prompt (Chrome/Edge desktop and Android) ---- */
    const installBtn = document.getElementById('install-app');
    let deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', function (event) {
        // Chrome shows its own mini-infobar unless we take the event over.
        event.preventDefault();
        deferredPrompt = event;
        if (installBtn) installBtn.hidden = false;
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        if (installBtn) installBtn.hidden = true;
    });

    if (installBtn) {
        installBtn.addEventListener('click', async function () {
            if (!deferredPrompt) return;
            installBtn.disabled = true;
            deferredPrompt.prompt();
            await deferredPrompt.userChoice;
            deferredPrompt = null;
            installBtn.hidden = true;
            installBtn.disabled = false;
        });
    }

    /* ---- Push opt-in ---- */
    if (!supported) return;

    function show(text, on, disabled) {
        label.textContent = text;
        btn.title = text;
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.disabled = !!disabled;
        btn.hidden = false;
    }

    /* No signing key means nobody can subscribe and nothing can be sent. This
       used to leave the bell hidden, which is indistinguishable from working —
       and is how push managed to reach nobody without anyone noticing. */
    if (!vapidKey) {
        if (isAdmin) {
            show('Alerts off — server has no push keys', false, true);
            btn.title = 'Set VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY in the server .env '
                      + '(php artisan nvn:vapid-keys), then php artisan config:cache. '
                      + 'Until then nobody can turn alerts on.';
        }
        return;
    }

    if (isIOS && !standalone) {
        show('Add to Home Screen for alerts', false, true);
        return;
    }

    if (Notification.permission === 'denied') {
        show('Alerts blocked in browser settings', false, true);
        return;
    }

    function keyToArray(b) {
        const pad = '='.repeat((4 - b.length % 4) % 4);
        const base64 = (b + pad).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from([...atob(base64)].map(c => c.charCodeAt(0)));
    }

    const encode = (buf) => buf ? btoa(String.fromCharCode(...new Uint8Array(buf))) : null;

    async function enable() {
        /* requestPermission has to run inside the click. Safari refuses it
           without a gesture, and Chrome blocks the site for good after two
           dismissals of a prompt the user never asked for. */
        const permission = await Notification.requestPermission();

        if (permission !== 'granted') {
            show(permission === 'denied' ? 'Alerts blocked in browser settings' : 'Alerts', false, permission === 'denied');
            return;
        }

        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription()
            || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToArray(vapidKey) });

        const saved = await fetch(@json(route('push.subscribe')), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({
                endpoint: sub.endpoint,
                p256dh:   encode(sub.getKey('p256dh')),
                auth:     encode(sub.getKey('auth')),
            }),
        });

        /* The browser is now subscribed whatever the server said. If the server
           did not store it, saying "Alerts on" would be a lie that lasts
           forever — the browser never asks again and nothing is ever sent. */
        if (!saved.ok) {
            show('Could not save alerts — try again', false, false);
            return;
        }

        show('Alerts on', true);
    }

    async function disable() {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (!sub) { show('Alerts', false); return; }

        const endpoint = sub.endpoint;
        await sub.unsubscribe();
        await fetch(@json(route('push.unsubscribe')), {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ endpoint }),
        });

        show('Alerts', false);
    }

    btn.addEventListener('click', function () {
        const on = btn.getAttribute('aria-pressed') === 'true';
        btn.disabled = true;
        (on ? disable() : enable())
            .catch(() => show('Alerts unavailable', false, true))
            .finally(() => { if (btn.title !== 'Alerts unavailable') btn.disabled = false; });
    });

    /* serviceWorker.ready never resolves and never rejects when the worker
       failed to install — it simply hangs, and the bell stays hidden forever
       with nothing on screen or in any log to say so. Cap the wait: a bell that
       is offered and fails on click is diagnosable; one that never appears is
       not. */
    const readyOrGiveUp = Promise.race([
        navigator.serviceWorker.ready,
        new Promise((_, reject) => setTimeout(() => reject(new Error('sw-timeout')), 5000)),
    ]);

    /* Reflect what the browser already holds, so a re-subscribe is not offered
       to somebody who is already subscribed on this device. */
    readyOrGiveUp
        .then((reg) => reg.pushManager.getSubscription())
        .then((sub) => show(sub && Notification.permission === 'granted' ? 'Alerts on' : 'Alerts',
                            !!sub && Notification.permission === 'granted'))
        .catch(() => show('Alerts', false));
})();
</script>
@endauth
