import { ref } from 'vue';
import { lsSet } from '@/ls';

// Night (default) vs daylight ops mode. The no-flash script in app.blade.php sets the
// initial <html> class from localStorage before paint; this composable is the source of
// truth thereafter and broadcasts changes (e.g. so the map can swap basemaps).
const KEY = 'toady-theme';

function current() {
    return document.documentElement.classList.contains('daylight') ? 'daylight' : 'night';
}

const theme = ref(current());

function apply(next) {
    const html = document.documentElement;
    html.classList.toggle('daylight', next === 'daylight');
    html.classList.toggle('dark', next !== 'daylight');
    lsSet(KEY, next);
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', next === 'daylight' ? '#e7edf3' : '#05070a');
    window.dispatchEvent(new CustomEvent('toady:theme', { detail: next }));
}

export function useTheme() {
    function toggle() {
        theme.value = theme.value === 'daylight' ? 'night' : 'daylight';
        apply(theme.value);
    }
    return { theme, toggle };
}
