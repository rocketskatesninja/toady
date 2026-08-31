// toady push-only service worker — caches NOTHING (deliberately, so it can never serve stale assets).
// Its single job is to surface background Web Push notifications.

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'toady', body: event.data ? event.data.text() : '' };
    }
    event.waitUntil(
        self.registration.showNotification(data.title || 'toady', {
            body: data.body || '',
            tag: data.tag || undefined,
            data: { url: data.url || '/dashboard' },
            vibrate: [120, 60, 120],
        })
    );
});

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
