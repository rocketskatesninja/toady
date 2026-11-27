import { ref, computed, watch } from 'vue';
import { lsGet, lsSet } from '@/ls';
import { useDeviceMode } from '@/useDeviceMode';

// "Big View" field mode — bumps the root font size ~20% so text, padding, and touch targets are easier to
// hit on a phone (no CSS zoom, so viewport units / fixed elements / the map stay in honest coordinates).
// Chosen separately for mobile and desktop mode (see useDeviceMode) and remembered per browser, so a
// phone-sized window can be big while the full-width one stays normal. The no-flash script in app.blade.php
// applies the same rule before paint; this composable is the source of truth thereafter.
const KEY = 'toady-fatfinger'; // + ':mobile' / ':desktop'; the bare key is the pre-split setting

const read = (m) => (lsGet(`${KEY}:${m}`) ?? lsGet(KEY)) === '1'; // fall back to the old single setting
const prefs = ref({ mobile: read('mobile'), desktop: read('desktop') });
const { mode } = useDeviceMode();
const on = computed(() => prefs.value[mode.value]); // the setting for the mode you're in

const apply = () => document.documentElement.classList.toggle('fat-finger', on.value);
watch(mode, apply); // crossing the breakpoint switches to the other mode's setting

export function useFatFinger() {
    function toggle() {
        prefs.value = { ...prefs.value, [mode.value]: !on.value };
        lsSet(`${KEY}:${mode.value}`, on.value ? '1' : '0');
        apply();
    }
    return { on, toggle };
}
