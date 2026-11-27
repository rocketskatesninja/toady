<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import maplibregl from '@/maplibre';
import { Satellite, RefreshCw } from 'lucide-vue-next';
import { BASEMAP, SATELLITE_TILES, mapTheme } from '@/maps';
import { useRadar } from '@/map/useRadar';

// A deliberately static radar map: centred on the op, zoomed out to a wide regional view so you can
// see weather moving in. No pan / zoom / rotate — the only control is the satellite toggle.
const props = defineProps({
    lat: { type: Number, required: true },
    lng: { type: Number, required: true },
});

const RADAR_ZOOM = 6; // highest zoom RainViewer radar tiles render at — one out from 7 ("zoom level not supported")

const el = ref(null);
const satelliteOn = ref(false);
const refreshing = ref(false);
let map = null, ro = null, roFrame = null;
const radar = useRadar({ get map() { return map; } }); // the sweep itself — shared with the op map's radar filter

// ---- satellite (inserted beneath the radar so the radar always reads on top) ----
function applySatellite() {
    if (!satelliteOn.value || !map || map.getSource('sat')) return;
    map.addSource('sat', { type: 'raster', tiles: [SATELLITE_TILES], tileSize: 256, attribution: 'Esri, Maxar' });
    map.addLayer({ id: 'sat', type: 'raster', source: 'sat', paint: { 'raster-opacity': 1 } }, radar.firstLayer());
}
function removeSatellite() {
    if (map?.getLayer('sat')) map.removeLayer('sat');
    if (map?.getSource('sat')) map.removeSource('sat');
}
function toggleSatellite() {
    satelliteOn.value = !satelliteOn.value;
    satelliteOn.value ? applySatellite() : removeSatellite();
}

// Pull the latest RainViewer frames and rebuild the radar (the frames are fetched once on mount, so the
// doppler goes stale as new sweeps publish ~every 10 min). New layers add on top, so radar stays above sat.
async function refreshRadar() {
    if (!map || refreshing.value) return;
    refreshing.value = true;
    try {
        if (await radar.load()) { radar.remove(); radar.apply(); } // keep the current radar when the fetch fails
    } finally {
        refreshing.value = false;
    }
}
// a basemap swap (theme change) wipes all layers → rebuild radar then satellite (beneath it)
function onThemeChange() {
    if (!map) return;
    map.setStyle(BASEMAP[mapTheme()]);
    map.once('style.load', () => { radar.forget(); radar.apply(); applySatellite(); });
}

onMounted(async () => {
    await radar.load();
    map = new maplibregl.Map({
        container: el.value,
        style: BASEMAP[mapTheme()],
        center: [props.lng, props.lat],
        zoom: RADAR_ZOOM,
        interactive: false, // static — no pan / zoom / rotate
        attributionControl: { compact: true },
    });
    map.on('load', () => {
        radar.apply();
        const attr = el.value?.querySelector('.maplibregl-ctrl-attrib');
        attr?.classList.remove('maplibregl-compact-show');
        attr?.removeAttribute('open');
    });
    window.addEventListener('toady:theme', onThemeChange);
    ro = new ResizeObserver(() => { if (roFrame) cancelAnimationFrame(roFrame); roFrame = requestAnimationFrame(() => map?.resize()); });
    ro.observe(el.value);
});

onBeforeUnmount(() => {
    window.removeEventListener('toady:theme', onThemeChange);
    radar.forget(); // stops the sweep; map.remove() below takes the layers
    if (roFrame) cancelAnimationFrame(roFrame);
    ro?.disconnect();
    map?.remove();
    map = null;
});

// recenter if the op's location moves
watch(() => [props.lat, props.lng], ([la, ln]) => { if (map && la != null) map.setCenter([ln, la]); });
</script>

<template>
    <div class="relative w-full h-full">
        <div ref="el" class="op-map w-full h-full"></div>
        <button @click="toggleSatellite" type="button"
            :class="satelliteOn ? 'border-accent text-accent' : 'border-line text-ink-dim'"
            class="absolute top-2 left-2 z-10 flex items-center gap-1 font-mono text-[10px] uppercase tracking-wider rounded border bg-surface/85 backdrop-blur px-1.5 py-1 hover:text-accent">
            <Satellite :size="12" /> sat
        </button>
        <button @click="refreshRadar" type="button" :disabled="refreshing" title="Refresh the radar"
            class="absolute top-2 right-2 z-10 flex items-center gap-1 font-mono text-[10px] uppercase tracking-wider rounded border border-line bg-surface/85 backdrop-blur px-1.5 py-1 text-ink-dim hover:text-accent disabled:opacity-50">
            <RefreshCw :size="12" :class="{ 'animate-spin': refreshing }" /> refresh
        </button>
    </div>
</template>
