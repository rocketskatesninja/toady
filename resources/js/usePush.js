// Web Push subscribe/unsubscribe against the push-only service worker.

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
}

export function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

export async function isSubscribed() {
    if (!pushSupported()) return false;
    const reg = await navigator.serviceWorker.getRegistration('/sw.js');
    if (!reg) return false;
    return !!(await reg.pushManager.getSubscription());
}

export async function enablePush(vapidPublicKey) {
    if (!pushSupported()) throw new Error('This device/browser does not support push.');
    if (!vapidPublicKey) throw new Error('Push is not configured on the server.');
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') throw new Error('Notifications are blocked — enable them in your browser settings.');

    const reg = await navigator.serviceWorker.register('/sw.js');
    await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
    });
    await window.axios.post('/push/subscribe', sub.toJSON());
    return true;
}

export async function disablePush() {
    const reg = await navigator.serviceWorker.getRegistration('/sw.js');
    const sub = reg && (await reg.pushManager.getSubscription());
    if (sub) {
        try { await window.axios.post('/push/unsubscribe', { endpoint: sub.endpoint }); } catch (e) { /* */ }
        await sub.unsubscribe();
    }
    return false;
}
