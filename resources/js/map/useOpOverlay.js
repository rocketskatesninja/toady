import { ref, computed, watch } from 'vue';
import { lsGet, lsSet } from '@/ls';
import { fieldsFromLinks, stackDepth, crossovers } from '@/geo';
import { plural } from '@/ui';
import { themeVar, featureCollection, point, line, showTitle, hideTitle } from '@/map/draw';

// The op map's overlay: lay another of your ops over this one and flag where the two get in each other's way.
// The other op's shape comes from /ops/{id}/outline (only what you could see on its own page). It's drawn in a
// neutral ink so it never reads as this plan; each crossover (see crossovers in @/geo) gets a coloured ring, and
// a chip sums them up — tap it to fly from one to the next.
// `ctx` is the map component's shared context: { map, ready, props, k, placed, raiseLines }.
export const HIT_COLORS = { crossing: '#f43f5e', shared: '#f59e0b', under: '#ea580c' }; // rose · amber · orange

export function useOpOverlay(ctx) {
    const HIT_PAINT = ['match', ['get', 'kind'], 'crossing', HIT_COLORS.crossing, 'shared', HIT_COLORS.shared, HIT_COLORS.under];
    const overlayId = ref(ctx.props.canOverlay ? lsGet(ctx.k('toady-map-overlay')) || '' : ''); // the other op's public id
    const overlay = ref(null);      // { name, links, portals } — the other op, in this map's shapes
    const overlayOps = ref(null);   // the picker's list (null = still loading)
    const overlayPick = ref(false); // picker open
    const overlayBusy = ref(false);
    const hitNow = ref('');         // what the chip's current (or last tapped) crossover is
    let hitIdx = -1;
    let overlayBound = false;       // layer hover/click handlers survive setStyle → bind exactly once
    watch(overlayId, (id) => lsSet(ctx.k('toady-map-overlay'), id || ''));

    async function toggleOverlayPicker() {
        overlayPick.value = !overlayPick.value;
        if (!overlayPick.value) return;
        try { overlayOps.value = (await window.axios.get(`/ops/${ctx.props.opId}/overlays`)).data; } catch (e) { overlayOps.value = []; }
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
        links: ctx.props.links,
        portals: ctx.placed().map((w) => ({ title: w.title, lng: w.lng, lat: w.lat })),
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
        if (!hits.value.length || !ctx.map) return;
        hitIdx = (hitIdx + 1) % hits.value.length;
        const h = hits.value[hitIdx];
        hitNow.value = `${hitIdx + 1}/${hits.value.length} · ${h.title}`;
        ctx.map.flyTo({ center: h.at, zoom: Math.max(ctx.map.getZoom(), 15) });
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
        if (!ctx.map || !ctx.ready) return; // the style's 'load' / 'style.load' redraw (reapplyStyleLayers) picks it up
        const data = overlayData();
        if (ctx.map.getSource('overlay-links')) {
            for (const [id, d] of Object.entries(data)) ctx.map.getSource(id).setData(d);
            ctx.map.setPaintProperty('overlay-fields', 'fill-opacity', overlayFieldOpacity(data['overlay-fields'].features));
            return;
        }
        if (!overlay.value) return;
        for (const [id, d] of Object.entries(data)) ctx.map.addSource(id, { type: 'geojson', data: d });
        const ink = themeVar('--color-ink', '#d6f5e8');
        ctx.map.addLayer({ id: 'overlay-fields', type: 'fill', source: 'overlay-fields', paint: { 'fill-color': ink, 'fill-opacity': overlayFieldOpacity(data['overlay-fields'].features) } });
        ctx.map.addLayer({ id: 'overlay-links', type: 'line', source: 'overlay-links', paint: { 'line-color': ink, 'line-width': 1.5, 'line-opacity': 0.75, 'line-dasharray': [1, 1.5] } });
        ctx.map.addLayer({ id: 'overlay-portals', type: 'circle', source: 'overlay-portals', paint: { 'circle-radius': 4.5, 'circle-opacity': 0, 'circle-stroke-width': 1.5, 'circle-stroke-color': ink } });
        // rings wide enough to circle a portal marker, so a conflict at a portal still shows
        ctx.map.addLayer({ id: 'overlay-hits', type: 'circle', source: 'overlay-hits', paint: { 'circle-radius': 15, 'circle-color': HIT_PAINT, 'circle-opacity': 0.12, 'circle-stroke-width': 2.5, 'circle-stroke-color': HIT_PAINT } });
        if (!overlayBound) { // hover = native tooltip (like the catalog dots); tap a ring = say what it is in the chip
            for (const id of ['overlay-hits', 'overlay-portals']) {
                ctx.map.on('mousemove', id, showTitle);
                ctx.map.on('mouseleave', id, hideTitle);
            }
            ctx.map.on('click', 'overlay-hits', (e) => { hitNow.value = e.features?.[0]?.properties.title || ''; });
            overlayBound = true;
        }
        ctx.raiseLines();
    }
    watch([overlay, hits], applyOverlay);

    return { overlayId, overlayOps, overlayPick, overlayBusy, hitNow, hits, overlayChip, toggleOverlayPicker, chooseOverlay, loadOverlay, nextHit, applyOverlay };
}
