const CACHE = 'nvn-v2';

/* Offline fallbacks. Two rules, learned the hard way:

   1. Every entry must actually exist. `/css/theme.css` and `/manifest.json`
      were listed here and neither is a real URL — the stylesheet is inlined in
      the layouts, and the manifest is a route at /manifest.webmanifest. A 404
      in this list is not a missing file, it is a dead service worker.
   2. Never use cache.addAll(): it rejects the whole batch if any one request
      fails, the rejection propagates out of waitUntil, and the install fails.
      An install that fails means no active worker, which means
      navigator.serviceWorker.ready never resolves — so the Alerts bell never
      un-hides, nobody can subscribe, and every web push silently reaches
      nobody. That is exactly what happened, on every device, for months, with
      no error anywhere on the server. Pre-caching is an optimisation; it must
      never be able to take push down with it. */
const PRECACHE = [
    '/',
    '/icons/icon-192.png',
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE)
            .then(cache => Promise.all(
                PRECACHE.map(url => cache.add(url).catch(() => {}))
            ))
            .catch(() => {})
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// Network-first for authenticated/API routes; cache-first for static assets
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Skip non-GET and cross-origin requests
    if (event.request.method !== 'GET' || url.origin !== location.origin) return;

    // Skip admin panel — always network
    if (url.pathname.startsWith('/admin-panel')) return;

    // Cache-first for CSS/fonts/images
    if (/\.(css|js|png|jpg|jpeg|gif|svg|woff2?|ico)$/.test(url.pathname)) {
        event.respondWith(
            caches.match(event.request).then(cached => cached || fetch(event.request).then(resp => {
                const clone = resp.clone();
                caches.open(CACHE).then(c => c.put(event.request, clone));
                return resp;
            }))
        );
        return;
    }

    // Network-first for HTML pages — show cached fallback if offline
    event.respondWith(
        fetch(event.request).catch(() => caches.match(event.request))
    );
});

// Web Push: show notification when a push message arrives
self.addEventListener('push', event => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (_) {}

    const title   = data.title   || 'Naija Virtual Notary';
    const options = {
        body:    data.body    || 'You have a new notification.',
        // The committed shield, not /brand — brand holds an uploaded icon that
        // may not be there. A 404 on these is silent: the OS quietly draws its
        // own default, so nothing ever reported that this was wrong.
        icon:    data.icon    || '/icons/icon-192.png',
        badge:   data.badge   || '/icons/icon-192.png',
        vibrate: data.vibrate || [200, 100, 200],
        data:    { url: data.url || '/' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// When the user taps a notification, open/focus the target URL
self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = event.notification.data && event.notification.data.url
        ? event.notification.data.url
        : '/';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
            for (const client of list) {
                if (client.url.includes(target) && 'focus' in client) return client.focus();
            }
            return clients.openWindow(target);
        })
    );
});
