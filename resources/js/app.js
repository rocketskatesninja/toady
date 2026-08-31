import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
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
    progress: { color: '#1cf0a0' },
});
