import { watch } from 'vue';

// Small pieces every part of the op map shares: theme colours, GeoJSON builders, the hover tooltip and the custom
// map buttons.

// Theme colours, read live so they follow day/night + faction (the accent is ENL green / RES blue).
export const themeVar = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
export const accentColor = () => themeVar('--color-accent', '#1cf0a0');

// GeoJSON builders for the map's own sources
export const featureCollection = (features) => ({ type: 'FeatureCollection', features });
export const point = (at, properties = {}) => ({ type: 'Feature', geometry: { type: 'Point', coordinates: at }, properties });
export const line = (coordinates, properties = {}) => ({ type: 'Feature', geometry: { type: 'LineString', coordinates }, properties });

// Hover a dot → name it with the map canvas's native `title`, exactly like the plan waypoint markers (which
// set div.title). Circle-layer features have no DOM of their own, so we drive the canvas's title off the
// layer's mousemove and clear it on leave — same native tooltip, same look, as the plan.
export function showTitle(e) {
    const f = e.features?.[0];
    if (!f) return;
    const canvas = e.target.getCanvas();
    canvas.style.cursor = 'pointer';
    canvas.title = f.properties.title;
}
/** Clear the tooltip — as a layer's mouseleave handler, or called with the map itself. */
export function hideTitle(mapOrEvent) {
    const canvas = (mapOrEvent.target || mapOrEvent).getCanvas();
    canvas.style.cursor = '';
    canvas.title = '';
}

// Custom map buttons (stacked below the geolocate button, top-right): an icon button that repaints itself
// (colour + title) whenever `source` changes — auto-zoom, lock, and the compass.
export function mapButton(icon, onClick, source, paint) {
    return {
        onAdd() {
            const c = document.createElement('div');
            c.className = 'maplibregl-ctrl maplibregl-ctrl-group';
            const b = document.createElement('button');
            b.type = 'button';
            b.style.cssText = 'width:29px;height:29px;display:flex;align-items:center;justify-content:center;';
            b.innerHTML = icon;
            const sync = () => paint(b);
            b.addEventListener('click', onClick);
            watch(source, sync); // re-sync on click OR when something else flips it (e.g. the paired toggle)
            sync();
            c.appendChild(b);
            return c;
        },
        onRemove() {},
    };
}
// an on/off map button: accent when on, muted when off
export const toggleControl = ({ icon, state, titleOn, titleOff }) => mapButton(icon, () => { state.value = !state.value; }, state, (b) => {
    b.style.color = state.value ? 'var(--color-accent)' : 'var(--color-ink-faint)';
    b.title = state.value ? titleOn : titleOff;
});
