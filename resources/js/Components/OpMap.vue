<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { Satellite, Radar, Car, Waypoints, MapPin, Triangle, Store, Layers } from 'lucide-vue-next';
import { BASEMAP, SATELLITE_TILES, HYBRID_ROAD_TILES, HYBRID_LABEL_TILES, mapTheme, loadRadarMeta, radarTiles } from '@/maps';
import { lsGet, lsSet, lsJSON } from '@/ls';
import { pathMeters, fieldsFromLinks, stackDepth, crossovers } from '@/geo';
import { plural } from '@/ui';

const WALK_MPS = 1.35; // client fallback ETA pace, mirrors RouteController

const trafficAvailable = computed(() => !!usePage().props.maps?.traffic);
const isOwner = computed(() => !!usePage().props.auth?.user?.is_owner); // the /catalog page is owner-only
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

// basemaps, satellite tiles + RainViewer radar helpers live in @/maps

// Theme colours, read live so they follow day/night + faction (the accent is ENL green / RES blue). The marker
// builders read them once per build, not per marker. Portal markers are coloured by STATUS, not type:
// untouched = grey, in progress = yellow, all directives complete = the faction accent, short on keys = red.
const themeVar = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
const accentColor = () => themeVar('--color-accent', '#1cf0a0');

// GeoJSON builders for the map's own sources
const featureCollection = (features) => ({ type: 'FeatureCollection', features });
const point = (at, properties = {}) => ({ type: 'Feature', geometry: { type: 'Point', coordinates: at }, properties });
const line = (coordinates, properties = {}) => ({ type: 'Feature', geometry: { type: 'LineString', coordinates }, properties });

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
const portalsOn = ref(props.editable && lsGet(k('toady-map-portals')) === '1'); // catalog overlay — planning only
const skipConfirm = ref(lsGet('toady-catalog-skip') === '1'); // user pref: skip the "add to plan" confirm popup
const autoZoom = ref(true); // fly the map to a portal when it's selected — toggled by the map control, sticky per-op
const locked = ref(false);  // lock the map in place — disables pan/zoom/rotate gestures (markers stay tappable); sticky per-op
const headingUp = ref(false); // rotate the map to the device compass (opt-in, mobile only); NOT persisted — a fresh tap re-arms the iOS sensor permission
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
watch(portalsOn, (on) => { lsSet(k('toady-map-portals'), on ? '1' : ''); on ? loadCatalog() : clearCatalog(); });
watch(skipConfirm, (v) => lsSet('toady-catalog-skip', v ? '1' : ''));
let radarHost = null;
let radarFrames = [];   // recent past frames (paths) for a sweeping animation
let radarLayers = [];   // one preloaded raster layer per frame
let radarFrame = 0;
let radarTimer = null;

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
    // order matters: an overlaid op, then the plan fields, links, and route sit above any raster overlay
    for (const id of ['overlay-fields', 'overlay-links', 'overlay-portals']) if (map.getLayer(id)) map.moveLayer(id);
    if (map.getLayer('plan-fields')) map.moveLayer('plan-fields');
    if (map.getLayer('plan-links')) map.moveLayer('plan-links');
    if (map.getLayer('plan-links-done')) map.moveLayer('plan-links-done');
    if (map.getLayer('route-line')) map.moveLayer('route-line');
    poiLayers().forEach((l) => map.moveLayer(l.id)); // landmarks on top, so field shading never washes out their labels
    if (map.getLayer('overlay-hits')) map.moveLayer('overlay-hits'); // crossover rings over everything
}

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

// ---- overlay: lay another of your ops over this one and flag where the two get in each other's way ----
// The other op's shape comes from /ops/{id}/outline (only what you could see on its own page). It's drawn in a
// neutral ink so it never reads as this plan; each crossover (see crossovers in @/geo) gets a coloured ring, and
// a chip sums them up — tap it to fly from one to the next.
const HIT_COLORS = { crossing: '#f43f5e', shared: '#f59e0b', under: '#ea580c' }; // rose · amber · orange
const HIT_PAINT = ['match', ['get', 'kind'], 'crossing', HIT_COLORS.crossing, 'shared', HIT_COLORS.shared, HIT_COLORS.under];
const overlayId = ref(props.canOverlay ? lsGet(k('toady-map-overlay')) || '' : ''); // the other op's public id
const overlay = ref(null);      // { name, links, portals } — the other op, in this map's shapes
const overlayOps = ref(null);   // the picker's list (null = still loading)
const overlayPick = ref(false); // picker open
const overlayBusy = ref(false);
const hitNow = ref('');         // what the chip's current (or last tapped) crossover is
let hitIdx = -1;
let overlayBound = false;       // layer hover/click handlers survive setStyle → bind exactly once
let styleReady = false;         // the basemap style is up (false during a day/night setStyle) — layers can be added
watch(overlayId, (id) => lsSet(k('toady-map-overlay'), id || ''));

async function toggleOverlayPicker() {
    overlayPick.value = !overlayPick.value;
    if (!overlayPick.value) return;
    try { overlayOps.value = (await window.axios.get(`/ops/${props.opId}/overlays`)).data; } catch (e) { overlayOps.value = []; }
}
function chooseOverlay(id) {
    overlayPick.value = false;
    overlayId.value = id || '';
    hitIdx = -1; hitNow.value = '';
    if (id) loadOverlay(); else overlay.value = null;
}
async function loadOverlay() {
    const id = overlayId.value;
    if (!id) return;
    overlayBusy.value = true;
    try {
        const { data } = await window.axios.get(`/ops/${id}/outline`);
        if (overlayId.value !== id) return; // switched to another op mid-request
        const at = Object.fromEntries(data.portals.map((p) => [p.id, [p.lng, p.lat]]));
        overlay.value = {
            name: data.name,
            portals: data.portals.map((p) => ({ title: p.title, lng: p.lng, lat: p.lat })),
            links: data.links.map(([from, to, done]) => ({ coords: [at[from], at[to]], done })),
        };
    } catch (e) {
        if (overlayId.value === id) { overlayId.value = ''; overlay.value = null; } // the op ended, or you're off its roster
    } finally { overlayBusy.value = false; }
}

// this plan, in the same shape as the other one
const minePlan = computed(() => ({
    links: props.links,
    portals: placed().map((w) => ({ title: w.title, lng: w.lng, lat: w.lat })),
}));
const conflicts = computed(() => (overlay.value ? crossovers(minePlan.value, overlay.value) : null));
const hits = computed(() => {
    const c = conflicts.value;
    if (!c) return [];
    const other = overlay.value.name;
    return [
        ...c.crossings.map((x) => ({ kind: 'crossing', at: x.at, title: `Link crossing: ${x.mine} (this op) × ${x.theirs} (${other})` })),
        ...c.shared.map((x) => ({ kind: 'shared', at: x.at, title: `In both plans: ${x.title}` })),
        ...c.underFields.map((x) => ({ kind: 'under', at: x.at, title: x.whose === 'mine'
            ? `${x.title} (this op) is under a ${other} field — it can't link out`
            : `${x.title} (${other}) is under a field of this op — it can't link out` })),
    ];
});
const overlayChip = computed(() => {
    const c = conflicts.value;
    if (!c) return null;
    return {
        name: overlay.value.name,
        parts: [
            c.crossings.length && { kind: 'crossing', text: plural(c.crossings.length, 'link crossing', 'link crossings') },
            c.shared.length && { kind: 'shared', text: plural(c.shared.length, 'shared portal', 'shared portals') },
            c.underFields.length && { kind: 'under', text: plural(c.underFields.length, 'under a field', 'under fields') },
        ].filter(Boolean),
    };
});
function nextHit() {
    if (!hits.value.length || !map) return;
    hitIdx = (hitIdx + 1) % hits.value.length;
    const h = hits.value[hitIdx];
    hitNow.value = `${hitIdx + 1}/${hits.value.length} · ${h.title}`;
    map.flyTo({ center: h.at, zoom: Math.max(map.getZoom(), 15) });
}

function overlayData() {
    const o = overlay.value;
    return {
        'overlay-fields': featureCollection(o ? fieldsFromLinks(o.links) : []),
        'overlay-links': featureCollection(o ? o.links.map((l) => line(l.coords)) : []),
        'overlay-portals': featureCollection(o ? o.portals.map((p) => point([p.lng, p.lat], { title: `${p.title} (${o.name})` })) : []),
        'overlay-hits': featureCollection(hits.value.map((h) => point(h.at, { kind: h.kind, title: h.title }))),
    };
}
// the other op's fields stack up to at most 25% at their deepest point, so a deep plan never blots out the map
const overlayFieldOpacity = (fields) => (fields.length ? 1 - 0.75 ** (1 / stackDepth(fields)) : 0);
function applyOverlay() {
    if (!map || !styleReady) return; // the style's 'load' / 'style.load' redraw (reapplyStyleLayers) picks it up
    const data = overlayData();
    if (map.getSource('overlay-links')) {
        for (const [id, d] of Object.entries(data)) map.getSource(id).setData(d);
        map.setPaintProperty('overlay-fields', 'fill-opacity', overlayFieldOpacity(data['overlay-fields'].features));
        return;
    }
    if (!overlay.value) return;
    for (const [id, d] of Object.entries(data)) map.addSource(id, { type: 'geojson', data: d });
    const ink = themeVar('--color-ink', '#d6f5e8');
    map.addLayer({ id: 'overlay-fields', type: 'fill', source: 'overlay-fields', paint: { 'fill-color': ink, 'fill-opacity': overlayFieldOpacity(data['overlay-fields'].features) } });
    map.addLayer({ id: 'overlay-links', type: 'line', source: 'overlay-links', paint: { 'line-color': ink, 'line-width': 1.5, 'line-opacity': 0.75, 'line-dasharray': [1, 1.5] } });
    map.addLayer({ id: 'overlay-portals', type: 'circle', source: 'overlay-portals', paint: { 'circle-radius': 4.5, 'circle-opacity': 0, 'circle-stroke-width': 1.5, 'circle-stroke-color': ink } });
    // rings wide enough to circle a portal marker, so a conflict at a portal still shows
    map.addLayer({ id: 'overlay-hits', type: 'circle', source: 'overlay-hits', paint: { 'circle-radius': 15, 'circle-color': HIT_PAINT, 'circle-opacity': 0.12, 'circle-stroke-width': 2.5, 'circle-stroke-color': HIT_PAINT } });
    if (!overlayBound) { // hover = native tooltip (like the catalog dots); tap a ring = say what it is in the chip
        for (const id of ['overlay-hits', 'overlay-portals']) {
            map.on('mousemove', id, showTitle);
            map.on('mouseleave', id, hideTitle);
        }
        map.on('click', 'overlay-hits', (e) => { hitNow.value = e.features?.[0]?.properties.title || ''; });
        overlayBound = true;
    }
    raiseLines();
}
watch([overlay, hits], applyOverlay);

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
    if (!radarOn.value || !radarFrames.length || radarLayers.length) return;
    const before = beforeRoute();
    // Preload each frame as its own raster layer — tiles are fetched once and cached, then we
    // animate by fading opacity between the loaded layers. No re-fetching → no rate-limiting.
    radarFrames.forEach((path, i) => {
        const id = `radar-${i}`;
        // RainViewer serves real radar only through z7; z8+ returns a "Zoom level not supported" placeholder
        // tile. Cap maxzoom so MapLibre overzooms the z7 tile past that instead of fetching (and getting stuck
        // showing) the placeholder — otherwise the warning lingers even after zooming back out.
        map.addSource(id, { type: 'raster', tiles: radarTiles(radarHost, path), tileSize: 256, maxzoom: 7, attribution: 'RainViewer' });
        map.addLayer({
            id, type: 'raster', source: id,
            paint: { 'raster-opacity': i === radarFrames.length - 1 ? 0.6 : 0, 'raster-fade-duration': 0 },
        }, before);
        radarLayers.push(id);
    });
    raiseLines();
    radarFrame = radarFrames.length - 1;
    if (radarLayers.length > 1) {
        radarTimer = setInterval(() => {
            const prev = radarFrame;
            radarFrame = (radarFrame + 1) % radarLayers.length;
            if (map.getLayer(radarLayers[prev])) map.setPaintProperty(radarLayers[prev], 'raster-opacity', 0);
            if (map.getLayer(radarLayers[radarFrame])) map.setPaintProperty(radarLayers[radarFrame], 'raster-opacity', 0.6);
        }, 600);
    }
}
function teardownRadar(removeFromMap) {
    if (radarTimer) { clearInterval(radarTimer); radarTimer = null; }
    if (removeFromMap) {
        radarLayers.forEach((id) => { if (map.getLayer(id)) map.removeLayer(id); if (map.getSource(id)) map.removeSource(id); });
    }
    radarLayers = [];
}
function removeRadar() { teardownRadar(true); }

// ---- catalog overlay: verified portals in view; tap one to add it to the plan ----
const CATALOG_MINZOOM = 12;     // below this a whole region would flood the cap — show a hint instead of a wall of dots
const catalogHint = ref(false); // "zoom in to load portals" (overlay on, but zoomed too far out)
let catalogReq = 0;             // guards against out-of-order fetches
let catalogTimer = null;        // debounces pan/zoom refetches
let catalogPopup = null;
let catalogBound = false;       // map.on('click', <layer>) handlers survive setStyle → bind exactly once

function setCatalogData(fc) {
    if (!map) return;
    if (map.getSource('catalog')) {
        map.getSource('catalog').setData(fc);
    } else {
        map.addSource('catalog', { type: 'geojson', data: fc });
        map.addLayer({
            id: 'catalog-portals', type: 'circle', source: 'catalog',
            // the viewer's faction colour (ENL green / RES blue), re-read whenever the layer rebuilds (e.g. day/night swap)
            paint: { 'circle-radius': 5, 'circle-color': accentColor(), 'circle-opacity': 0.9, 'circle-stroke-width': 1.5, 'circle-stroke-color': '#04121a' },
        }, beforeRoute());
        if (!catalogBound) { // bind once; handlers persist across setStyle even though the layer is rebuilt
            map.on('click', 'catalog-portals', onCatalogClick);
            map.on('mousemove', 'catalog-portals', showTitle); // hover → name tooltip, like the plan markers
            map.on('mouseleave', 'catalog-portals', hideTitle);
            catalogBound = true;
        }
    }
    if (map.getLayer('catalog-portals')) map.setLayoutProperty('catalog-portals', 'visibility', 'visible');
    raiseLines(); // keep plan links + route above the catalog dots
}

function clearCatalog() {
    catalogHint.value = false;
    catalogPopup?.remove();
    if (map) hideTitle();
    if (map?.getLayer('catalog-portals')) map.setLayoutProperty('catalog-portals', 'visibility', 'none');
}

async function loadCatalog() {
    if (!portalsOn.value || !map) return;
    if (map.getZoom() < CATALOG_MINZOOM) { // too far out — don't flood; hint to zoom in
        catalogHint.value = true;
        if (map.getSource('catalog')) map.getSource('catalog').setData(featureCollection([]));
        return;
    }
    catalogHint.value = false;
    const b = map.getBounds();
    const myReq = ++catalogReq;
    try {
        const { data } = await window.axios.get('/api/catalog/in-view', { params: { n: b.getNorth(), s: b.getSouth(), e: b.getEast(), w: b.getWest() } });
        if (myReq !== catalogReq || !portalsOn.value) return; // superseded or toggled off mid-flight
        // don't offer portals already in the plan — matched by position (a plan portal added from the catalog sits on
        // its exact coordinates; the op page's portals carry no catalog GUID to match on)
        const spot = (lat, lng) => `${(+lat).toFixed(5)},${(+lng).toFixed(5)}`;
        const have = new Set(placed().map((w) => spot(w.lat, w.lng)));
        setCatalogData(featureCollection(data.filter((p) => !have.has(spot(p.lat, p.lng)))
            .map((p) => point([p.lng, p.lat], { id: p.id, guid: p.guid, title: p.title || '(unnamed portal)', image: p.image || '' }))));
    } catch (e) { /* transient — leave the last set shown */ }
}

function scheduleCatalog() { clearTimeout(catalogTimer); catalogTimer = setTimeout(loadCatalog, 300); }

// Hover a dot → name it with the map canvas's native `title`, exactly like the plan waypoint markers (which
// set div.title). Circle-layer features have no DOM of their own, so we drive the canvas's title off the
// layer's mousemove and clear it on leave — same native tooltip, same look, as the plan.
function showTitle(e) {
    const f = e.features?.[0];
    if (!f) return;
    const canvas = map.getCanvas();
    canvas.style.cursor = 'pointer';
    canvas.title = f.properties.title;
}
function hideTitle() {
    const canvas = map.getCanvas();
    canvas.style.cursor = '';
    canvas.title = '';
}

function onCatalogClick(e) {
    const f = e.features?.[0];
    if (!f || !portalsOn.value) return;
    hideTitle(); // the add popup takes over from the hover name
    const p = f.properties;
    if (skipConfirm.value) { emit('add-catalog', { id: +p.id }); return; } // one-tap add (user opted out of the prompt)
    openAddPopup(f.geometry.coordinates.slice(), p);
}

function openAddPopup(coords, p) {
    catalogPopup?.remove();
    const wrap = document.createElement('div');
    wrap.className = 'catalog-add';

    if (p.image) { // catalog photo (Niantic) — only harvested portals have one
        const img = document.createElement('img');
        img.className = 'catalog-add-img';
        img.src = p.image; img.alt = ''; img.loading = 'lazy';
        wrap.appendChild(img);
    }
    const title = document.createElement('div');
    title.className = 'catalog-add-title';
    title.textContent = p.title; // textContent, never innerHTML — a catalog title is user-supplied
    wrap.appendChild(title);

    const skip = document.createElement('label');
    skip.className = 'catalog-add-skip';
    const cb = document.createElement('input'); cb.type = 'checkbox';
    const cbTxt = document.createElement('span'); cbTxt.textContent = "Don't ask again";
    skip.append(cb, cbTxt);

    const add = document.createElement('button');
    add.type = 'button'; add.className = 'catalog-add-btn'; add.textContent = 'Add to plan';
    add.addEventListener('click', () => {
        if (cb.checked) skipConfirm.value = true; // future taps add immediately
        emit('add-catalog', { id: +p.id });
        catalogPopup?.remove();
    });
    wrap.appendChild(add);

    if (isOwner.value) { // jump to this portal on the (owner-only) catalog page
        const open = document.createElement('button');
        open.type = 'button'; open.className = 'catalog-add-link'; open.textContent = 'Open in catalog';
        open.addEventListener('click', () => { catalogPopup?.remove(); router.visit('/catalog?q=' + encodeURIComponent(p.title || '') + '&focus=' + (+p.id)); });
        wrap.appendChild(open);
    }
    wrap.appendChild(skip);

    catalogPopup = new maplibregl.Popup({ closeButton: true, closeOnClick: true, offset: 12, maxWidth: '240px' }).setLngLat(coords).setDOMContent(wrap).addTo(map);
}

function togglePortals() { portalsOn.value = !portalsOn.value; }

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
    // After a basemap swap the radar layers are gone; reset our ids (without trying to remove) and rebuild.
    if (radarOn.value) { teardownRadar(false); applyRadar(); }
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
    radarOn.value ? applyRadar() : removeRadar();
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

// Custom map buttons (stacked below the geolocate button, top-right): an icon button that repaints itself
// (colour + title) whenever `source` changes — auto-zoom, lock, and the compass.
function mapButton(icon, onClick, source, paint) {
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
// an on/off map button: accent when on, muted when off (persisted per op by its own watcher below)
const toggleControl = ({ icon, state, titleOn, titleOff }) => mapButton(icon, () => { state.value = !state.value; }, state, (b) => {
    b.style.color = state.value ? 'var(--color-accent)' : 'var(--color-ink-faint)';
    b.title = state.value ? titleOn : titleOff;
});
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

// ---- heading-up: rotate the map to the device's compass heading (opt-in, mobile only) ----
const compassState = ref('off'); // off | waiting | live | nodata — drives the button colour + title + hint
let orientationHandler = null;
let bearingRAF = 0;
let lastBearing = 0;
let gotAbsolute = false;
let waitTimer = null;
let touching = false; // fingers on the map — pause heading-up so the compass can't fight pan/pinch gestures
const onTouch = (e) => { touching = e.touches.length > 0; }; // e.touches = fingers still down (multi-touch safe)

// A device-orientation event → compass heading (deg clockwise from north), corrected for screen rotation.
// iOS exposes webkitCompassHeading directly; others use alpha (counter-clockwise from north).
function headingFromEvent(e) {
    let h;
    if (typeof e.webkitCompassHeading === 'number') h = e.webkitCompassHeading;
    else if (typeof e.alpha === 'number') h = 360 - e.alpha;
    else return null;
    const screenAngle = (typeof screen !== 'undefined' && screen.orientation && screen.orientation.angle) || 0;
    return (h + screenAngle + 360) % 360;
}

function onOrientation(e) {
    const absolute = e.absolute === true || e.type === 'deviceorientationabsolute' || typeof e.webkitCompassHeading === 'number';
    if (absolute) gotAbsolute = true;
    else if (gotAbsolute) return; // once a true compass is flowing, ignore the drifting relative stream
    const h = headingFromEvent(e);
    if (h == null) return;
    compassState.value = 'live';
    // low-pass toward the new heading, shortest way round the circle, so the map glides instead of jittering
    const diff = ((h - lastBearing + 540) % 360) - 180;
    lastBearing = (lastBearing + diff * 0.2 + 360) % 360;
    if (!bearingRAF) bearingRAF = requestAnimationFrame(() => { bearingRAF = 0; if (map && headingUp.value && !touching) map.setBearing(lastBearing); });
}

function enableHeadingUp() {
    const begin = () => {
        if (locked.value) locked.value = false;  // a locked map can't rotate
        map?.dragRotate?.disable?.();             // the sensor drives rotation — no manual fighting
        gotAbsolute = false;
        orientationHandler = onOrientation;
        // listen to BOTH: the absolute (true-north) stream when the device has it, plus the plain event as a fallback
        window.addEventListener('deviceorientationabsolute', orientationHandler, true);
        window.addEventListener('deviceorientation', orientationHandler, true);
        headingUp.value = true;
        compassState.value = 'waiting';
        clearTimeout(waitTimer);
        waitTimer = setTimeout(() => { if (compassState.value === 'waiting') compassState.value = 'nodata'; }, 3000);
    };
    // iOS 13+ requires an in-gesture permission prompt; everything else can just start listening
    const req = window.DeviceOrientationEvent && DeviceOrientationEvent.requestPermission;
    if (typeof req === 'function') DeviceOrientationEvent.requestPermission().then((s) => { if (s === 'granted') begin(); else compassState.value = 'nodata'; }).catch(() => { compassState.value = 'nodata'; });
    else begin();
}

// stop listening to the compass (heading-up off, or the map going away)
function stopOrientation() {
    clearTimeout(waitTimer);
    if (orientationHandler) {
        window.removeEventListener('deviceorientationabsolute', orientationHandler, true);
        window.removeEventListener('deviceorientation', orientationHandler, true);
        orientationHandler = null;
    }
    if (bearingRAF) { cancelAnimationFrame(bearingRAF); bearingRAF = 0; }
}
function disableHeadingUp() {
    stopOrientation();
    headingUp.value = false;
    compassState.value = 'off';
    lastBearing = 0; gotAbsolute = false;
    if (!locked.value) map?.dragRotate?.enable?.();
    map?.easeTo?.({ bearing: 0, duration: 300 }); // settle back to north-up
}

// styled like the other map toggles, but its click runs enable/disable directly so the iOS permission
// request stays inside the user gesture (a watcher would defer it out of the gesture and fail on iOS)
const compassControl = mapButton(
    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>',
    () => { headingUp.value ? disableHeadingUp() : enableHeadingUp(); },
    compassState,
    (b) => {
        const s = compassState.value;
        b.style.color = s === 'live' ? 'var(--color-accent)' : s === 'waiting' ? '#fbbf24' : s === 'nodata' ? '#fb7185' : 'var(--color-ink-faint)';
        b.title = s === 'live' ? 'Heading-up: on (tap for north-up)'
            : s === 'waiting' ? 'Heading-up: waiting for compass…'
                : s === 'nodata' ? 'No compass data — your browser may be blocking motion sensors (Brave Shields / private mode / iOS Motion access)'
                    : 'Rotate the map to your compass heading';
    },
);

onMounted(() => {
    if (overlayId.value) loadOverlay(); // a remembered overlay — drawn once the style is ready
    loadRadarMeta().then((m) => { radarHost = m.host; radarFrames = m.frames; });
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
    if (radarTimer) clearInterval(radarTimer);
    if (catalogTimer) clearTimeout(catalogTimer);
    catalogPopup?.remove();
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

<style>
.op-map .maplibregl-ctrl-attrib { font-size: 9px; }
/* auto-hide the on-map controls once the map's been idle; the ⓘ attribution stays visible */
.op-map-root .maplibregl-ctrl { transition: opacity .3s ease; }
.op-map-root.map-idle .maplibregl-ctrl:not(.maplibregl-ctrl-attrib) { opacity: 0; pointer-events: none; }

/* catalog "add to plan" popup — themed to the app instead of MapLibre's white default */
.op-map-root .maplibregl-popup-content { background: var(--color-surface); color: var(--color-ink); border: 1px solid var(--color-line); border-radius: 8px; box-shadow: 0 6px 24px rgba(0, 0, 0, .45); padding: 10px 12px; }
.op-map-root .maplibregl-popup-close-button { color: var(--color-ink-faint); font-size: 15px; padding: 0 4px; }
.op-map-root .maplibregl-popup-anchor-top .maplibregl-popup-tip,
.op-map-root .maplibregl-popup-anchor-top-left .maplibregl-popup-tip,
.op-map-root .maplibregl-popup-anchor-top-right .maplibregl-popup-tip { border-bottom-color: var(--color-surface); }
.op-map-root .maplibregl-popup-anchor-bottom .maplibregl-popup-tip,
.op-map-root .maplibregl-popup-anchor-bottom-left .maplibregl-popup-tip,
.op-map-root .maplibregl-popup-anchor-bottom-right .maplibregl-popup-tip { border-top-color: var(--color-surface); }
.op-map-root .maplibregl-popup-anchor-left .maplibregl-popup-tip { border-right-color: var(--color-surface); }
.op-map-root .maplibregl-popup-anchor-right .maplibregl-popup-tip { border-left-color: var(--color-surface); }
.catalog-add { font-family: ui-monospace, monospace; min-width: 160px; display: flex; flex-direction: column; gap: 8px; }
.catalog-add-img { width: 100%; height: 96px; object-fit: cover; border-radius: 5px; display: block; background: var(--color-inset, #0a1410); }
.catalog-add-title { font-weight: 600; font-size: 12px; color: var(--color-ink); }
.catalog-add-btn { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; font-weight: 700; background: var(--color-accent); color: #04121a; border: none; border-radius: 5px; padding: 5px 8px; cursor: pointer; }
.catalog-add-link { font-size: 10px; font-family: ui-monospace, monospace; color: var(--color-ink-dim); background: none; border: 1px solid var(--color-line); border-radius: 5px; padding: 4px 8px; cursor: pointer; }
.catalog-add-link:hover { color: var(--color-accent); border-color: var(--color-accent); }
.catalog-add-skip { display: flex; align-items: center; gap: 5px; font-size: 10px; color: var(--color-ink-dim); cursor: pointer; }
.catalog-add-skip input { accent-color: var(--color-accent); }
</style>
