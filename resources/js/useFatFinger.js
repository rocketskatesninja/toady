import { ref } from 'vue';
import { lsSet } from '@/ls';

// "Big View" field mode — bumps the root font size ~20% so text, padding, and touch targets are easier to
// hit on a phone (no CSS zoom, so viewport units / fixed elements / the map stay in honest coordinates).
// The no-flash script in app.blade.php sets the initial <html> class from localStorage before paint; this
// composable is the source of truth thereafter. Per-device (localStorage), so your desktop stays normal.
const KEY = 'toady-fatfinger';
const on = ref(typeof document !== 'undefined' && document.documentElement.classList.contains('fat-finger'));

export function useFatFinger() {
    function toggle() {
        on.value = !on.value;
        document.documentElement.classList.toggle('fat-finger', on.value);
        lsSet(KEY, on.value ? '1' : '0');
    }
    return { on, toggle };
}
