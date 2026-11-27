import { ref } from 'vue';

// The app's two layout modes — 'mobile' (≤767px wide) or 'desktop' — from one shared media query. The op
// dashboard keeps a grid per mode, and the menu style + Big View are remembered per mode. (The no-flash script
// in app.blade.php uses the same breakpoint.)
const mql = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(max-width: 767px)') : null;
const mode = ref(mql?.matches ? 'mobile' : 'desktop');
mql?.addEventListener('change', (e) => { mode.value = e.matches ? 'mobile' : 'desktop'; });

export function useDeviceMode() {
    return { mode };
}
