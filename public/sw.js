// toady push-only service worker — caches NOTHING (deliberately, so it can never serve stale assets).
// Its single job is to surface background Web Push notifications.

// Nothing is cached, so a new version can take over at once instead of waiting for every tab to close.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'toady', body: event.data ? event.data.text() : '' };
    }
    event.waitUntil(show(data));
});

async function show(d) {
    const tag = d.tag || 'toady';
    // Close an earlier alert with the same tag first. Firefox ignores `renotify`, so a fresh notification is the only
    // way a repeat (the next "hack ready", another DM) is sure to alert again instead of silently replacing the last.
    for (const old of await self.registration.getNotifications({ tag })) old.close();
    let body = d.body || '';
    if (d.test && d.sent) body = `${body} (delivered in ${((Date.now() - d.sent) / 1000).toFixed(1)} s)`.trim();
    const opts = {
        body,
        tag,
        renotify: true,
        silent: !!d.silent,                       // buzz switched off
        timestamp: d.sent || Date.now(),
        data: { url: d.url || '/dashboard' },
    };
    if (!d.silent) opts.vibrate = [120, 60, 120]; // never together with silent — Chrome rejects that combination
    return self.registration.showNotification(d.title || 'toady', opts);
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/dashboard';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
            for (const w of wins) {
                if (w.url.includes(url) && 'focus' in w) return w.focus();
            }
            return clients.openWindow ? clients.openWindow(url) : null;
        })
    );
});
