const CACHE = 'nvn-v1';

// Static assets to pre-cache on install
const PRECACHE = [
    '/',
    '/css/theme.css',
    '/manifest.json',
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE).then(cache => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
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
        icon:    data.icon    || '/brand/icon-192.png',
        badge:   data.badge   || '/brand/icon-192.png',
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
