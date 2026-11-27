<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import maplibregl from '@/maplibre';
import { Satellite, Radar, Car, Waypoints, MapPin, Triangle, Store, Layers } from 'lucide-vue-next';
import { BASEMAP, SATELLITE_TILES, HYBRID_ROAD_TILES, HYBRID_LABEL_TILES, mapTheme } from '@/maps';
import { lsGet, lsSet, lsJSON } from '@/ls';
import { pathMeters, fieldsFromLinks, stackDepth } from '@/geo';
import { plural } from '@/ui';
import { themeVar, accentColor, featureCollection, line, toggleControl } from '@/map/draw';
import { useRadar } from '@/map/useRadar';
import { useHeadingUp } from '@/map/useHeadingUp';
import { useCatalogOverlay } from '@/map/useCatalogOverlay';
import { useOpOverlay, HIT_COLORS } from '@/map/useOpOverlay';

// The op's map: plan markers, agent beacons, the routed agent's path, the plan's links / fields / range rings and the
// filter stack. Its bigger features live beside it in @/map: the compass (useHeadingUp), the catalog dots
// (useCatalogOverlay), another op laid over this one (useOpOverlay) and the weather radar (useRadar).

const WALK_MPS = 1.35; // client fallback ETA pace, mirrors RouteController

const trafficAvailable = computed(() => !!usePage().props.maps?.traffic);
const TRAFFIC_TILES = '/map/traffic/{z}/{x}/{y}'; // proxied; key stays server-side

const props = defineProps({
    opId: { type: String, default: null },
    waypoints: { type: Array, default: () => [] },
    presence: { type: Array, default: () => [] },          // live agents: {user_id,callsign,faction,lat,lng}
    statuses: { type: Object, default: () => ({}) },         // per-waypoint: id → 'untouched' | 'active' | 'complete' | 'needkeys'
    selection: { type: Object, default: null },              // { type:'waypoint'|'user', id }
    links: { type: Array, default: () => [] },               // planned field links: [{ coords: [[lng,lat],[lng,lat]], done }, ...]
    routeIds: { type: Array, default: () => [] },            // a single agent's predicted route: ordered waypoint ids (empty = no route)
    routeColor: { type: String, default: null },             // the routed agent's assigned colour (falls back to orange)
    editable: { type: Boolean, default: false },             // planning phase — enables the catalog overlay's "add to plan"
    canOverlay: { type: Boolean, default: false },           // this op's operatives can lay another of their ops over the map
});
const ROUTE_FALLBACK = '#f97316'; // fixed orange when the routed agent has no assigned colour
const emit = defineEmits(['select', 'drop', 'route-info', 'add-catalog']); // route-info: { distance, duration, mode } | null ; add-catalog: { id }

const el = ref(null);
let map = null;
let markers = [];
let agentMarkers = [];
let routeGeo = null;    // cached road-following geometry from OSRM
let routeSig = '';      // waypoint signature the cached route was built for
let routeReq = 0;       // guards against out-of-order async responses
let ro = null;          // keeps the canvas sized to the widget (grid resize / late layout)
let roFrame = null;

let styleReady = false; // the basemap style is up (false during a day/night setStyle) — layers can be added

// basemaps, satellite tiles + RainViewer radar helpers live in @/maps; colours + GeoJSON builders in @/map/draw.
// Portal markers are coloured by STATUS, not type: untouched = grey, in progress = yellow, all directives
// complete = the faction accent, short on keys = red. The marker builders read the theme once per build.

// Map settings are remembered PER-OP (suffix the key with the op id), so each op keeps its own filters,
// lock/auto-zoom, and saved view. Falls back to the bare key if there's no op id (shouldn't happen in-app).
const k = (name) => (props.opId ? `${name}:${props.opId}` : name);

// an on/off filter remembered per op on this device ('1' / '0'); `on` is its default before it's ever toggled
function stickyFilter(name, on) {
    const flag = ref(on ? lsGet(k(name)) !== '0' : lsGet(k(name)) === '1');
    watch(flag, (v) => lsSet(k(name), v ? '1' : '0'));
    return flag;
}
const _sat = lsGet(k('toady-map-sat'));
const satMode = ref(_sat === '1' ? 'sat' : (['sat', 'hybrid', 'off'].includes(_sat) ? _sat : 'off')); // off | sat | hybrid
watch(satMode, (v) => lsSet(k('toady-map-sat'), v));
const radarOn = stickyFilter('toady-map-radar', false);
const trafficOn = stickyFilter('toady-map-traffic', false);
const linksOn = stickyFilter('toady-map-links', true);          // the planned field links — shown by default
const fieldsOn = stickyFilter('toady-map-fields', true);        // the fields those links close — shown by default
const landmarksOn = stickyFilter('toady-map-landmarks', false); // the basemap's restaurants, stores, … — hidden by default
const autoZoom = ref(true); // fly the map to a portal when it's selected — toggled by the map control, sticky per-op
const locked = ref(false);  // lock the map in place — disables pan/zoom/rotate gestures (markers stay tappable); sticky per-op
locked.value = lsGet(k('toady-maplock')) === '1';
autoZoom.value = lsGet(k('toady-autozoom')) !== '0';
if (locked.value) autoZoom.value = false; // mutually exclusive — a locked map can't auto-zoom

// Auto-hide the on-map controls after a few idle seconds (immersive field view). Any pointer/wheel
// activity over the map brings them straight back; the ⓘ attribution never hides (licensing).
const idle = ref(false);
let idleTimer = null;
function poke() {
    idle.value = false;
    clearTimeout(idleTimer);
    idleTimer = setTimeout(() => { idle.value = !overlayPick.value; }, 4000); // never fade out an open picker
}

// ---- markers (DOM-based; survive setStyle) ----
function buildMarkers() {
    markers.forEach((m) => m.remove());
    markers = [];
    const accent = accentColor(); // read the theme accent once per build, not per marker
    const colorFor = (s) => (s === 'needkeys' ? '#f43f5e' : s === 'complete' ? accent : s === 'active' ? '#facc15' : '#6b7d82');
    props.waypoints.forEach((w, i) => {
        if (w.lat == null || w.lng == null) return;   // generic location, not placed yet
        const sel = props.selection?.type === 'waypoint' && props.selection.id === w.id;
        const color = colorFor(props.statuses?.[w.id] || 'untouched');
        const ring = sel ? `0 0 0 3px #f4fff9, 0 0 16px ${color}` : `0 0 0 1px ${color}66, 0 0 10px ${color}88`;
        const div = document.createElement('div');
        div.style.cssText = `width:${sel ? 30 : 24}px;height:${sel ? 30 : 24}px;border-radius:50%;display:flex;align-items:center;justify-content:center;
            font:600 11px ui-monospace,monospace;color:#02110b;cursor:pointer;border:2px solid #02110b;transition:all .15s;
            background:${color};box-shadow:${ring}`;
        div.textContent = i + 1;
        div.title = `${w.title || '(untitled)'} · ${w.role}`;
        div.addEventListener('click', (e) => { e.stopPropagation(); emit('select', { type: 'waypoint', id: w.id }); });
        markers.push(new maplibregl.Marker({ element: div }).setLngLat([w.lng, w.lat]).addTo(map));
    });
}

function buildAgents() {
    agentMarkers.forEach((m) => m.remove());
    agentMarkers = [];
    // theme colours read once per build: name-tag bg + each faction's colour (follow day/night)
    const labelBg = themeVar('--color-bg', '#04070b') + 'dd';
    const enl = themeVar('--color-enl', '#1cf0a0');
    const res = themeVar('--color-res', '#38bdf8');
    props.presence.forEach((a) => {
        if (a.lat == null || a.lng == null) return;
        const sel = props.selection?.type === 'user' && props.selection.id === a.user_id;
        const color = a.color || (a.faction === 'RES' ? res : enl); // operator-assigned colour, else faction (theme-driven)
        const dot = sel ? 18 : 14;
        const wrap = document.createElement('div');
        wrap.style.cssText = 'display:flex;flex-direction:column;align-items:center;cursor:pointer;';
        // build with textContent (NOT innerHTML) so a hostile callsign can't inject markup
        const label = document.createElement('span');
        label.style.cssText = `font:600 9px ui-monospace,monospace;color:${color};background:${labelBg};padding:1px 4px;border-radius:2px;white-space:nowrap;margin-bottom:2px;text-shadow:0 0 6px ${color}`;
        label.textContent = a.callsign;
        const dotEl = document.createElement('span');
        dotEl.style.cssText = `width:${dot}px;height:${dot}px;border-radius:50%;background:${color};border:2px solid #04070b;box-shadow:0 0 0 ${sel ? 5 : 4}px ${color}${sel ? '55' : '33'}, 0 0 12px ${color}`;
        wrap.append(label, dotEl);
        wrap.addEventListener('click', (e) => { e.stopPropagation(); emit('select', { type: 'user', id: a.user_id }); });
        agentMarkers.push(new maplibregl.Marker({ element: wrap }).setLngLat([a.lng, a.lat]).addTo(map));
    });
}

// ---- route (street-by-street via OSRM, falls back to straight) ----
const placed = () => props.waypoints.filter((w) => w.lat != null && w.lng != null);
// the route is a single agent's predicted path: the waypoints named in routeIds, in that order,
// limited to ones actually placed on the map. Empty routeIds → no route line.
const routeWaypoints = () => {
    const byId = Object.fromEntries(placed().map((w) => [w.id, w]));
    return props.routeIds.map((id) => byId[id]).filter(Boolean);
};
function routeData() {
    return line(routeGeo ? routeGeo.coordinates : routeWaypoints().map((w) => [w.lng, w.lat]));
}
function drawRoute() {
    const data = routeData();
    if (data.geometry.coordinates.length < 2) {
        if (map.getLayer('route-line')) map.removeLayer('route-line');
        if (map.getSource('route')) map.removeSource('route');
        return;
    }
    const lineColor = props.routeColor || ROUTE_FALLBACK; // the routed agent's colour (else orange)
    if (map.getSource('route')) { map.getSource('route').setData(data); map.setPaintProperty('route-line', 'line-color', lineColor); return; }
    map.addSource('route', { type: 'geojson', data });
    map.addLayer({
        id: 'route-line', type: 'line', source: 'route',
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': lineColor, 'line-width': 3, 'line-opacity': 0.85, 'line-dasharray': [0, 2] }, // the routed agent's colour — stays distinct from accent links
    });
}
// Fetch a walking route through the agent's waypoints in order (via our backend proxy, which holds
// the OpenRouteService key); only refetch when that set of waypoints changes.
async function computeRoute() {
    if (!map || !props.opId) return;
    const located = routeWaypoints();
    if (located.length < 2) { routeGeo = null; routeSig = ''; drawRoute(); emit('route-info', null); return; }
    const coordinates = located.map((w) => [w.lng, w.lat]);
    const sig = coordinates.map((c) => `${c[0].toFixed(5)},${c[1].toFixed(5)}`).join(';');
    if (sig === routeSig && routeGeo) { drawRoute(); return; } // unchanged — keep the last emitted readout
    routeSig = sig;
    drawRoute(); // show the straight line immediately while we route
    const myReq = ++routeReq;
    try {
        const { data } = await window.axios.post(`/ops/${props.opId}/route`, { coordinates });
        if (myReq !== routeReq) return; // a newer request superseded this one
        if (data?.geometry?.coordinates?.length) { routeGeo = data.geometry; drawRoute(); }
        emit('route-info', { distance: data?.distance ?? null, duration: data?.duration ?? null, mode: data?.mode ?? 'direct' });
    } catch (e) {
        // keep the straight-line fallback + report its length so the readout still shows something
        const m = pathMeters(coordinates);
        emit('route-info', { distance: m, duration: m / WALK_MPS, mode: 'direct' });
    }
}

// keep overlay rasters/lines beneath the route line so it always reads on top
const beforeRoute = () => (map.getLayer('route-line') ? 'route-line' : undefined);
// keep the plan links + route line above any raster overlay (sat/traffic/radar) so they never get hidden
function raiseLines() {
    // order matters: the catalog dots, an overlaid op, then the plan fields, links, and route sit above any raster
    // overlay — the dots first, so a satellite/traffic/radar layer switched on later can't bury them, yet they stay
    // under the plan's own lines
    for (const id of ['catalog-portals', 'overlay-fields', 'overlay-links', 'overlay-portals']) if (map.getLayer(id)) map.moveLayer(id);
    if (map.getLayer('plan-fields')) map.moveLayer('plan-fields');
    if (map.getLayer('plan-links')) map.moveLayer('plan-links');
    if (map.getLayer('plan-links-done')) map.moveLayer('plan-links-done');
    if (map.getLayer('route-line')) map.moveLayer('route-line');
    poiLayers().forEach((l) => map.moveLayer(l.id)); // landmarks on top, so field shading never washes out their labels
    if (map.getLayer('overlay-hits')) map.moveLayer('overlay-hits'); // crossover rings over everything
}

// What the features in @/map need from this component: the live map + whether its style is up, the props, and the
// layer-ordering helpers. Getters, because `map` and `styleReady` are reassigned.
const ctx = {
    get map() { return map; },
    get ready() { return styleReady; },
    props, emit, k, placed, raiseLines, beforeRoute,
};
const radar = useRadar(ctx, beforeRoute);
const { headingUp, compassState, compassControl, onTouch, stopOrientation, disableHeadingUp } = useHeadingUp(ctx, locked);
const { portalsOn, catalogHint, loadCatalog, scheduleCatalog, togglePortals, dispose: disposeCatalog } = useCatalogOverlay(ctx);
const { overlayId, overlayOps, overlayPick, overlayBusy, hitNow, hits, overlayChip, toggleOverlayPicker, chooseOverlay, loadOverlay, nextHit, applyOverlay } = useOpOverlay(ctx);

// raster overlays (satellite, traffic) — one add/remove shape, layered below the route + plan lines
function addRaster(id, source, opacity) {
    if (map.getSource(id)) return;
    map.addSource(id, source);
    map.addLayer({ id, type: 'raster', source: id, paint: { 'raster-opacity': opacity } }, beforeRoute());
    raiseLines();
}
function removeRaster(id) {
    if (map.getLayer(id)) map.removeLayer(id);
    if (map.getSource(id)) map.removeSource(id);
}
function applySatellite() {
    if (satMode.value === 'off') return;
    addRaster('sat', { type: 'raster', tiles: [SATELLITE_TILES], tileSize: 256, attribution: 'Esri, Maxar' }, 1);
    if (satMode.value === 'hybrid') { // overlay transparent roads + place labels on the imagery
        addRaster('hybrid-road', { type: 'raster', tiles: [HYBRID_ROAD_TILES], tileSize: 256, attribution: 'Esri' }, 1);
        addRaster('hybrid-label', { type: 'raster', tiles: [HYBRID_LABEL_TILES], tileSize: 256, attribution: 'Esri' }, 1);
    }
}
function applyTraffic() {
    if (trafficOn.value) addRaster('traffic', { type: 'raster', tiles: [TRAFFIC_TILES], tileSize: 256, minzoom: 2, attribution: 'TomTom' }, 0.75);
}

// planned field links (from `link` directives): pending = dashed, thrown (directive done) = solid + brighter
function applyPlanLinks() {
    if (!map) return;
    const data = featureCollection(props.links.map((l) => line(l.coords, { done: !!l.done })));
    if (map.getSource('plan-links')) { map.getSource('plan-links').setData(data); return; }
    if (!props.links.length) return;
    map.addSource('plan-links', { type: 'geojson', data });
    const vis = linksOn.value ? 'visible' : 'none';
    map.addLayer({
        id: 'plan-links', type: 'line', source: 'plan-links', filter: ['!=', ['get', 'done'], true],
        layout: { visibility: vis },
        paint: { 'line-color': accentColor(), 'line-width': 1.5, 'line-opacity': 0.5, 'line-dasharray': [2, 1.5] },
    }, beforeRoute());
    map.addLayer({
        id: 'plan-links-done', type: 'line', source: 'plan-links', filter: ['==', ['get', 'done'], true],
        layout: { visibility: vis },
        paint: { 'line-color': accentColor(), 'line-width': 2, 'line-opacity': 0.9 },
    }, beforeRoute());
    raiseLines();
}

// planned fields (every triangle the plan's links close — see fieldsFromLinks in @/geo). Overlapping fields stack,
// so a layered plan reads darker where it layers; the shading is scaled to the plan's deepest stack (stackDepth).
function fieldOpacity(fields) {
    const depth = stackDepth(fields);
    const total = Math.min(0.45, 0.2 + 0.05 * (depth - 1)); // how dark the deepest point gets once every field is thrown
    const a = 1 - (1 - total) ** (1 / depth);               // the per-field opacity that stacks up to exactly that
    return ['case', ['get', 'done'], a, a / 2];             // fields still waiting on a link: half as strong
}
function applyPlanFields() {
    if (!map) return;
    const features = fieldsFromLinks(props.links);
    const data = featureCollection(features);
    if (map.getSource('plan-fields')) {
        map.getSource('plan-fields').setData(data);
        if (features.length) map.setPaintProperty('plan-fields', 'fill-opacity', fieldOpacity(features));
        return;
    }
    if (!features.length) return;
    map.addSource('plan-fields', { type: 'geojson', data });
    map.addLayer({
        id: 'plan-fields', type: 'fill', source: 'plan-fields',
        layout: { visibility: fieldsOn.value ? 'visible' : 'none' },
        paint: { 'fill-color': accentColor(), 'fill-opacity': fieldOpacity(features) },
    }, beforeRoute());
    raiseLines();
}

// Landmarks (restaurants, stores, …) = the basemap's POI layers, shown only while the filter is on. The day
// style has its own; the night style has none, so it gets one in the same shape (same tiles + icons). Both get
// the same labels: the theme's main text colour on a thick halo, so they read over the tiles and field shading
// (the day style's own grey was too faint). Both start at zoom 14, the furthest out the map tiles carry places (the day
// style's own layers wait for 15–17, so they're opened up to match).
const LANDMARKS_MINZOOM = 14;
const landmarksHint = ref(false); // "zoom in to see landmarks" (filter on, but zoomed too far out)
const poiLayers = () => (map.getStyle()?.layers || []).filter((l) => l['source-layer'] === 'poi');
const landmarkText = () => ({ 'text-color': themeVar('--color-ink', '#d6f5e8'), 'text-halo-color': themeVar('--color-surface', '#0a121a'), 'text-halo-width': 1.6, 'text-halo-blur': 0.2 });
function applyLandmarks() {
    if (!map) return;
    if (landmarksOn.value && !poiLayers().length && map.getSource('openmaptiles')) {
        map.addLayer({
            id: 'landmarks', type: 'symbol', source: 'openmaptiles', 'source-layer': 'poi', minzoom: LANDMARKS_MINZOOM,
            filter: ['match', ['geometry-type'], ['MultiPoint', 'Point'], true, false],
            layout: {
                'icon-image': ['match', ['get', 'subclass'], ['florist', 'furniture'], ['get', 'subclass'], ['get', 'class']],
                'symbol-sort-key': ['get', 'rank'], // the most notable places win when labels collide
                'text-field': ['coalesce', ['get', 'name_en'], ['get', 'name']],
                'text-font': ['Noto Sans Regular'], 'text-size': 12, 'text-anchor': 'top', 'text-offset': [0, 0.6], 'text-max-width': 9,
            },
            paint: landmarkText(),
        });
        raiseLines();
    }
    const v = landmarksOn.value ? 'visible' : 'none';
    const text = Object.entries(landmarkText());
    poiLayers().forEach((l) => {
        map.setLayoutProperty(l.id, 'visibility', v);
        map.setLayerZoomRange(l.id, LANDMARKS_MINZOOM, 24);
        text.forEach(([prop, val]) => map.setPaintProperty(l.id, prop, val));
    });
    syncLandmarksHint();
}
function syncLandmarksHint() { landmarksHint.value = landmarksOn.value && !!map && map.getZoom() < LANDMARKS_MINZOOM; }

// 40 m interaction-range rings around each placed plan portal (real ground geometry, so they scale with
// zoom). Only drawn once zoomed in enough to read (native layer minzoom) — no clutter at the overview.
const RANGE_M = 40;
const RANGE_MINZOOM = 15;
function ringCoords(lng, lat, meters, steps = 48) {
    const dLat = meters / 111320;
    const dLng = meters / (111320 * Math.cos((lat * Math.PI) / 180));
    const ring = [];
    for (let i = 0; i <= steps; i++) { const a = (i / steps) * 2 * Math.PI; ring.push([lng + dLng * Math.cos(a), lat + dLat * Math.sin(a)]); }
    return ring;
}
function applyRangeRings() {
    if (!map) return;
    const data = featureCollection(placed().map((w) => line(ringCoords(w.lng, w.lat, RANGE_M))));
    if (map.getSource('range')) { map.getSource('range').setData(data); return; }
    map.addSource('range', { type: 'geojson', data });
    map.addLayer({
        id: 'range-rings', type: 'line', source: 'range', minzoom: RANGE_MINZOOM,
        paint: { 'line-color': accentColor(), 'line-width': 1.2, 'line-opacity': 0.3 },
    }, beforeRoute());
    raiseLines();
}

function applyRadar() {
    if (!radarOn.value) return;
    radar.apply();
    raiseLines();
}

// the filter stack (top-left), top to bottom — each button lights up while its layer is on
const filterButtons = computed(() => [
    { key: 'sat', icon: Satellite, label: satMode.value === 'hybrid' ? 'hybrid' : 'sat', on: satMode.value !== 'off', toggle: toggleSatellite },
    { key: 'radar', icon: Radar, label: 'radar', on: radarOn.value, toggle: toggleRadar },
    { key: 'links', icon: Waypoints, label: 'links', on: linksOn.value, toggle: toggleLinks },
    { key: 'fields', icon: Triangle, label: 'fields', on: fieldsOn.value, toggle: toggleFields, title: "Shade the fields the plan's links close (stronger once all three links are thrown)" },
    props.canOverlay && { key: 'overlay', icon: Layers, label: overlayBusy.value ? 'loading' : 'overlay', on: !!overlayId.value, toggle: toggleOverlayPicker, title: 'Lay another of your ops over this map and flag where they cross' },
    props.editable && { key: 'portals', icon: MapPin, label: 'portals', on: portalsOn.value, toggle: togglePortals, title: 'Show cataloged portals — tap one to add it to the plan' },
    { key: 'landmarks', icon: Store, label: 'landmarks', on: landmarksOn.value, toggle: toggleLandmarks, title: 'Show restaurants, stores and other places (zoom in to neighborhood level)' },
    trafficAvailable.value && { key: 'traffic', icon: Car, label: 'traffic', on: trafficOn.value, toggle: toggleTraffic },
].filter(Boolean));
const zoomHints = computed(() => [catalogHint.value && 'Zoom in to load cataloged portals', landmarksHint.value && 'Zoom in to see landmarks'].filter(Boolean));

function reapplyStyleLayers() {
    styleReady = true;
    drawRoute();
    applySatellite();
    applyTraffic(); // source was wiped by the basemap swap; re-add if it's on
    applyLandmarks(); // the new basemap brings its own POI layers back visible — re-hide them if the filter's off
    applyOverlay();
    applyPlanFields();
    applyPlanLinks();
    applyRangeRings();
    // After a basemap swap the radar layers are gone; forget our ids (without trying to remove) and rebuild.
    if (radarOn.value) { radar.forget(); applyRadar(); }
    if (portalsOn.value) loadCatalog(); // the catalog source/layer were wiped too — rebuild them
}

function fit() {
    const pts = placed();
    if (!pts.length) return;
    const b = new maplibregl.LngLatBounds();
    pts.forEach((w) => b.extend([w.lng, w.lat]));
    map.fitBounds(b, { padding: 50, maxZoom: 15, duration: 0 });
}
// ---- control toggles ----
function toggleSatellite() {                       // cycle: off → satellite → hybrid → off
    satMode.value = satMode.value === 'off' ? 'sat' : (satMode.value === 'sat' ? 'hybrid' : 'off');
    removeRaster('sat'); removeRaster('hybrid-road'); removeRaster('hybrid-label');
    applySatellite();
}
function toggleRadar() {
    radarOn.value = !radarOn.value;
    radarOn.value ? applyRadar() : radar.remove();
}
function toggleTraffic() {
    trafficOn.value = !trafficOn.value;
    trafficOn.value ? applyTraffic() : removeRaster('traffic');
}
function toggleLinks() {
    linksOn.value = !linksOn.value;
    const v = linksOn.value ? 'visible' : 'none';
    if (map.getLayer('plan-links')) map.setLayoutProperty('plan-links', 'visibility', v);
    if (map.getLayer('plan-links-done')) map.setLayoutProperty('plan-links-done', 'visibility', v);
}
function toggleFields() {
    fieldsOn.value = !fieldsOn.value;
    if (map.getLayer('plan-fields')) map.setLayoutProperty('plan-fields', 'visibility', fieldsOn.value ? 'visible' : 'none');
}
function toggleLandmarks() {
    landmarksOn.value = !landmarksOn.value;
    applyLandmarks();
}

function onThemeChange() {
    if (!map) return;
    styleReady = false;
    map.setStyle(BASEMAP[mapTheme()]);
    map.once('style.load', reapplyStyleLayers); // layers rebuild on the new basemap
    // markers + agent dots survive setStyle, but their inline colours were baked at build time —
    // rebuild them so the day/night palette applies immediately, not only after a refresh
    buildMarkers();
    buildAgents();
}

watch(() => props.links, () => { if (styleReady) { applyPlanFields(); applyPlanLinks(); } }, { deep: true });

const autoZoomControl = toggleControl({
    icon: '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="22" y1="12" x2="18" y2="12"/><line x1="6" y1="12" x2="2" y2="12"/><line x1="12" y1="6" x2="12" y2="2"/><line x1="12" y1="22" x2="12" y2="18"/></svg>',
    state: autoZoom,
    titleOn: 'Auto-zoom to selection: on (tap to disable)', titleOff: 'Auto-zoom to selection: off (tap to enable)',
});

// enable/disable the map's interaction gestures to match `locked` (the +/- buttons + marker taps still work)
function applyLock() {
    for (const h of ['dragPan', 'scrollZoom', 'doubleClickZoom', 'touchZoomRotate', 'touchPitch', 'dragRotate', 'boxZoom', 'keyboard']) {
        map?.[h]?.[locked.value ? 'disable' : 'enable']?.();
    }
}

// remember the map view so a LOCKED map reopens where you left it across refreshes (sticky per-op)
function savedView() {
    const v = lsJSON(k('toady-mapview')); return v && Array.isArray(v.center) ? v : null;
}
function saveView() {
    if (headingUp.value) return; // don't thrash localStorage while the compass is continuously rotating the map
    lsSet(k('toady-mapview'), JSON.stringify({ center: map.getCenter().toArray(), zoom: map.getZoom() }));
}

const lockControl = toggleControl({
    icon: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
    state: locked,
    titleOn: 'Map locked in place (tap to unlock pan & zoom)', titleOff: 'Lock the map in place',
});

// auto-zoom and lock are mutually exclusive — a locked map can't fly to a selection, so enabling one disables the other
watch(autoZoom, (on) => { lsSet(k('toady-autozoom'), on ? '1' : '0'); if (on) locked.value = false; });
watch(locked, (on) => { lsSet(k('toady-maplock'), on ? '1' : '0'); applyLock(); if (on) { autoZoom.value = false; if (headingUp.value) disableHeadingUp(); } });

onMounted(() => {
    if (overlayId.value) loadOverlay(); // a remembered overlay — drawn once the style is ready
    radar.load();
    const startView = locked.value ? savedView() : null; // a locked map reopens at its saved view instead of re-fitting
    map = new maplibregl.Map({
        container: el.value,
        style: BASEMAP[mapTheme()],
        center: startView ? startView.center : (props.waypoints.length ? [props.waypoints[0].lng, props.waypoints[0].lat] : [-81.49, 31.15]),
        zoom: startView ? startView.zoom : 11,
        attributionControl: { compact: true },
    });
    // The third-party basemap style references a couple of sprite images its sprite sheet doesn't ship
    // (e.g. "wood-pattern" / "circle-11"). Those layers already draw fine without them; drop in a 1×1
    // transparent placeholder so MapLibre stops logging styleimagemissing. (Re-fires after a setStyle.)
    map.on('styleimagemissing', (e) => {
        if (e.id && !map.hasImage(e.id)) map.addImage(e.id, { width: 1, height: 1, data: new Uint8Array(4) });
    });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    map.addControl(new maplibregl.GeolocateControl({ positionOptions: { enableHighAccuracy: true }, trackUserLocation: true }), 'top-right');
    map.addControl(autoZoomControl, 'top-right');
    map.addControl(lockControl, 'top-right');
    // heading-up only makes sense with a compass — show it on touch devices that report orientation
    if (window.matchMedia?.('(pointer: coarse)')?.matches && 'DeviceOrientationEvent' in window) map.addControl(compassControl, 'top-right');
    applyLock(); // honor a persisted lock on load
    map.on('moveend', saveView); // remember the view so a locked map reopens where you left it
    map.on('moveend', () => { if (portalsOn.value) scheduleCatalog(); }); // refetch the catalog overlay for the new viewport
    map.on('zoomend', syncLandmarksHint);
    map.on('load', () => {
        buildMarkers(); buildAgents(); reapplyStyleLayers(); computeRoute(); if (!startView) fit();
        // MapLibre's compact attribution starts expanded — collapse it to just the ⓘ button
        const attr = el.value?.querySelector('.maplibregl-ctrl-attrib');
        attr?.classList.remove('maplibregl-compact-show');
        attr?.removeAttribute('open');
    });
    map.on('click', (e) => {
        // a tap on a catalog dot is handled by its own layer click (add-to-plan) — don't also drop a waypoint there
        overlayPick.value = false; // a tap on the map closes the overlay picker
        if (portalsOn.value && map.getLayer('catalog-portals') && map.queryRenderedFeatures(e.point, { layers: ['catalog-portals'] }).length) return;
        if (map.getLayer('overlay-hits') && map.queryRenderedFeatures(e.point, { layers: ['overlay-hits'] }).length) return; // a crossover ring says what it is
        emit('drop', { lat: +e.lngLat.lat.toFixed(6), lng: +e.lngLat.lng.toFixed(6) });
    });
    // while a finger is on the map, pause heading-up's bearing updates so the compass can't fight pan/pinch
    const cv = map.getCanvasContainer();
    ['touchstart', 'touchend', 'touchcancel'].forEach((t) => cv.addEventListener(t, onTouch, { passive: true }));
    window.addEventListener('toady:theme', onThemeChange);

    // The widget often lays out (or resizes) after the map initializes → keep the canvas in sync.
    ro = new ResizeObserver(() => {
        if (roFrame) cancelAnimationFrame(roFrame);
        roFrame = requestAnimationFrame(() => map?.resize());
    });
    ro.observe(el.value);
    poke(); // start the idle countdown so the controls fade if untouched
});

onBeforeUnmount(() => {
    clearTimeout(idleTimer);
    window.removeEventListener('toady:theme', onThemeChange);
    stopOrientation();
    radar.forget(); // stops the sweep; map.remove() below takes the layers
    disposeCatalog();
    if (roFrame) cancelAnimationFrame(roFrame);
    ro?.disconnect();
    map?.remove();
    map = null;
});

// Plan changes → redraw. Markers + agent dots are DOM (they only need the map); everything drawn into the style
// waits for styleReady — not isStyleLoaded(), which reads false while tiles are still loading, so a change that
// landed mid-pan/zoom used to be skipped.
watch(() => props.waypoints, () => {
    if (!map) return;
    buildMarkers();
    if (styleReady) { computeRoute(); applyRangeRings(); if (portalsOn.value) loadCatalog(); }
}, { deep: true });
watch(() => props.routeIds, () => { if (styleReady) computeRoute(); }, { deep: true });
watch(() => props.routeColor, () => { if (map?.getLayer('route-line')) map.setPaintProperty('route-line', 'line-color', props.routeColor || ROUTE_FALLBACK); });
watch(() => props.statuses, () => { if (map) buildMarkers(); }, { deep: true });
watch(() => props.presence, () => { if (map) buildAgents(); }, { deep: true });

// Selection (from a marker or a widget) → fly in + re-highlight; clearing flies back to the full view.
watch(() => props.selection, (sel) => {
    if (!map) return;
    buildMarkers();
    buildAgents();
    if (!autoZoom.value) return; // auto-zoom off → re-highlight only, leave the view where the user put it
    if (!sel) { fit(); return; }
    if (sel.type === 'waypoint') {
        const w = props.waypoints.find((x) => x.id === sel.id);
        if (w && w.lat != null) map.flyTo({ center: [w.lng, w.lat], zoom: 17, speed: 1.4 });
    } else if (sel.type === 'user') {
        const a = props.presence.find((x) => x.user_id === sel.id);
        if (a && a.lat != null) map.flyTo({ center: [a.lng, a.lat], zoom: 17, speed: 1.4 });
    }
}, { deep: true });
</script>

<template>
    <div class="relative w-full h-full op-map-root" :class="{ 'map-idle': idle }"
        @pointermove.capture.passive="poke" @pointerdown.capture.passive="poke" @wheel.capture.passive="poke">
        <div ref="el" class="op-map w-full h-full"></div>
        <!-- only shown when heading-up gets no compass data (sensor blocked or unavailable) -->
        <div v-if="compassState === 'nodata'" class="absolute bottom-2 left-2 z-10 max-w-[85%] font-mono text-[10px] leading-tight px-1.5 py-1 rounded border border-rose-500/40 daylight:border-rose-700/50 bg-surface/90 text-rose-300 daylight:text-rose-700">
            No compass data — your browser may be blocking motion sensors (e.g. Brave Shields / private mode).
        </div>
        <!-- overlay picker — placed against the map (beside the button stack) so it never runs off a narrow map -->
        <div v-if="overlayPick" class="absolute top-2 left-[6.25rem] z-20 w-56 max-w-[calc(100%-7rem)] max-h-[calc(100%-1rem)] overflow-y-auto op-scroll rounded border border-line bg-surface/95 backdrop-blur shadow-xl font-mono">
            <div class="px-2 pt-1.5 pb-1 text-[10px] uppercase tracking-wider text-ink-faint">Overlay another op</div>
            <div v-if="overlayOps === null" class="px-2 pb-2 text-[11px] text-ink-dim">Loading…</div>
            <div v-else-if="!overlayOps.length" class="px-2 pb-2 text-[11px] text-ink-dim">No other ops to compare — you're not in any others right now.</div>
            <button v-for="o in overlayOps" :key="o.id" type="button" @click="chooseOverlay(o.id)"
                class="w-full block px-2 py-1.5 text-left hover:bg-emerald-500/10" :class="o.id === overlayId ? 'text-accent' : 'text-ink'">
                <span class="block truncate text-[11px]">{{ o.name }}</span>
                <span class="block text-[9px] uppercase tracking-wider text-ink-faint">{{ o.status }} · {{ plural(o.portals, 'portal', 'portals') }}</span>
            </button>
            <button v-if="overlayId" type="button" @click="chooseOverlay(null)"
                class="w-full block px-2 py-1.5 text-left text-[11px] text-ink-dim hover:text-rose-400 border-t border-line">Clear overlay</button>
        </div>
        <!-- hints + the overlay chip: a click-through strip between the button stack and the ⓘ, so they never sit under either -->
        <div v-if="zoomHints.length || overlayChip" class="absolute bottom-2 left-[6.25rem] right-10 z-10 flex flex-col items-center gap-1 pointer-events-none">
            <div v-for="hint in zoomHints" :key="hint" class="whitespace-nowrap font-mono text-[10px] leading-tight px-2 py-1 rounded border border-cyan-500/40 daylight:border-cyan-700/50 bg-surface/90 text-cyan-300 daylight:text-cyan-700">{{ hint }}</div>
            <!-- overlay summary: what the overlaid op runs into; tap to fly from one crossover to the next -->
            <button v-if="overlayChip" type="button" @click="nextHit"
                class="pointer-events-auto max-w-full font-mono text-[10px] leading-tight px-2 py-1 rounded border bg-surface/90 backdrop-blur text-left"
                :class="overlayChip.parts.length ? 'border-rose-500/40 text-ink' : 'border-accent/40 text-accent'"
                :title="hits.length ? 'Tap to visit each crossover in turn' : ''">
                <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span class="uppercase tracking-wider text-ink-dim truncate max-w-40">{{ overlayChip.name }}</span>
                    <span v-if="!overlayChip.parts.length">no crossover</span>
                    <span v-for="part in overlayChip.parts" :key="part.kind" class="inline-flex items-center gap-1 whitespace-nowrap">
                        <span class="w-2 h-2 rounded-full" :style="{ background: HIT_COLORS[part.kind] }"></span>{{ part.text }}
                    </span>
                </span>
                <span v-if="hitNow" class="block mt-0.5 text-ink-dim">{{ hitNow }}</span>
            </button>
        </div>
        <!-- scanner overlay controls (gap-1 keeps all eight inside a phone's default-size map, even in Big View) -->
        <div class="absolute top-2 left-2 z-10 flex flex-col gap-1 transition-opacity duration-300" :class="{ 'opacity-0 pointer-events-none': idle }">
            <button v-for="f in filterButtons" :key="f.key" @click="f.toggle" type="button" :title="f.title"
                :class="f.on ? 'border-accent text-accent' : 'border-line text-ink-dim'"
                class="flex items-center gap-1 font-mono text-[10px] uppercase tracking-wider rounded border bg-surface/85 backdrop-blur px-1.5 py-1 hover:text-accent">
                <component :is="f.icon" :size="12" /> {{ f.label }}
            </button>
        </div>
    </div>
</template>
