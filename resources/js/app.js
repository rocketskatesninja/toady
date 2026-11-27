import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const appName = import.meta.env.VITE_APP_NAME || 'toady';

// grid-layout-plus's interact.js can throw an uncaught TypeError from a stale press-and-hold timer when a
// dashboard widget re-renders mid-press (e.g. flipping op status refreshes the grid). The interaction is
// already gone, so it's harmless — swallow just that one error so it can't surface as a crash.
if (typeof window !== 'undefined') {
    window.addEventListener('error', (e) => {
        if (e?.error?.stack?.includes('autoStartHoldTimer')) e.preventDefault();
    });
}

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: { color: 'var(--color-accent)' }, // the loading bar follows the viewer's faction accent
});

// After a deploy the server answers an old page's requests with a version change. Inertia does a full
// load for a normal visit but silently drops it for an async one, and the op page's live poll
// (router.reload) is async: an op left open across a deploy would keep polling and never update.
// Do the full load for both, except on a page the user has typed on: a background reload must never
// wipe a half-written entry, and Inertia still does the full load on their next click.
let typedOnPath = null;
document.addEventListener('input', () => (typedOnPath = window.location.pathname), true);
router.on('location', (event) => {
    if (!event.detail.versionChange || typedOnPath === window.location.pathname) return;
    const target = event.detail.url;
    if (target.href.split('#')[0] === window.location.href.split('#')[0]) {
        window.location.reload();
    } else {
        window.location.href = target.href;
    }
    return false;
});
