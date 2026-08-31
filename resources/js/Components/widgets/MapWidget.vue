<script setup>
import { inject, computed, ref, watch } from 'vue';
import { Radio, Crosshair, Navigation, Footprints, Route } from 'lucide-vue-next';
import OpMap from '@/Components/OpMap.vue';
import { fmtDistance, fmtDuration } from '@/geo';
import { shortestOrder } from '@/routeorder';
import { lsGet, lsSet } from '@/ls';

const c = inject('opctx');

// distance + on-foot ETA for the previewed agent's route, emitted by the map after it routes.
// mode 'direct' = straight-line (a routing service was unreachable), flagged so the number reads honestly.
const routeInfo = ref(null);

// "optimize walk" reorders the previewed agent's farm/capture/setup stops to shorten the walk.
// It NEVER reorders link throws (physics: you can't link out through a field) — those keep fielding order.
const optKey = `toady-route-opt:${c.data.op.id}`;
const optimize = ref(lsGet(optKey) === '1');
watch(optimize, (v) => lsSet(optKey, v ? '1' : ''));

// per-waypoint status → marker colour: needkeys (short on keys — takes priority), untouched (no directives
// done), active (some directives done), complete (all done)
const statuses = computed(() => {
    const m = {};
    for (const w of c.data.waypoints) {
        const t = c.data.steps.filter((s) => s.op_waypoint_id === w.id);
        const done = t.filter((s) => s.done).length;
        const held = c.data.keyHoldings.filter((h) => h.op_waypoint_id === w.id).reduce((sum, h) => sum + h.qty, 0);
        if (w.keys_needed > 0 && held < w.keys_needed) m[w.id] = 'needkeys'; // still short on keys → red
        else m[w.id] = !t.length || done === 0 ? 'untouched' : done === t.length ? 'complete' : 'active';
    }
    return m;
});

// planned field links: each `link` directive (origin waypoint → target waypoint id in s.links)
const planLinks = computed(() => {
    const byId = {};
    for (const w of c.data.waypoints) if (w.lat != null) byId[w.id] = w;
    const segs = [];
    for (const s of c.data.steps) {
        if (s.action !== 'link' || !Array.isArray(s.links)) continue;
        const from = byId[s.op_waypoint_id];
        if (!from) continue;
        for (const t of s.links) {
            const to = byId[t];
            if (to) segs.push({ coords: [[from.lng, from.lat], [to.lng, to.lat]], done: !!s.done });
        }
    }
    return segs;
});

// the Roster picked an agent to preview → route through that agent's placed portals. Default is waypoint
// (fielding) order; with "optimize walk" on, the free stops are reordered to shorten the walk while the
// link-throw stops stay in fielding order — done efficiently first, then the throws in sequence.
const routeStops = computed(() => {
    if (!c.routeAgent.value) return [];
    const mine = c.data.steps.filter((s) => s.assignee_id === c.routeAgent.value && s.op_waypoint_id);
    const mineWpIds = new Set(mine.map((s) => s.op_waypoint_id));
    return c.data.waypoints.filter((w) => mineWpIds.has(w.id) && w.lat != null);
});
const routeIds = computed(() => {
    const stops = routeStops.value;
    if (!optimize.value || stops.length < 3) return stops.map((w) => w.id);
    const linkWpIds = new Set(c.data.steps.filter((s) => s.assignee_id === c.routeAgent.value && s.action === 'link').map((s) => s.op_waypoint_id));
    const free = stops.filter((w) => !linkWpIds.has(w.id));
    const throws = stops.filter((w) => linkWpIds.has(w.id));
    if (free.length < 2) return stops.map((w) => w.id); // nothing worth reordering
    const live = (c.data.presence || []).find((p) => p.user_id === c.routeAgent.value && p.lat != null);
    const start = live ? [live.lng, live.lat] : null;
    return [...shortestOrder(free, start).map((w) => w.id), ...throws.map((w) => w.id)];
});
const canOptimize = computed(() => routeStops.value.length >= 3);

// operator-assigned agent colours: tint each live beacon, and the routed agent's path, to match.
const colorByUser = computed(() => Object.fromEntries(c.data.participants.filter((p) => p.color).map((p) => [p.user_id, p.color])));
const presence = computed(() => c.data.presence.map((a) => ({ ...a, color: colorByUser.value[a.user_id] || null })));
const routeColor = computed(() => (c.routeAgent.value ? colorByUser.value[c.routeAgent.value] || null : null));

// the readout only makes sense while a route is drawn; clear it the moment the preview turns off
watch(routeIds, (ids) => { if (ids.length < 2) routeInfo.value = null; });

function onSelect(payload) { c.select(payload.type, payload.id); }
function onBackground(coords) {
    // a tap on empty map = drop a waypoint (if in drop mode) else clear the current selection
    if (c.dropMode.value) c.onMapDrop(coords);
    else c.clearSelection();
}
</script>

<template>
    <div class="h-full flex flex-col">
        <div class="shrink-0 flex items-center justify-end gap-3 px-1.5 pt-2 pb-1.5 text-[11px] font-mono">
            <span class="mr-auto flex items-center gap-1 text-accent/70"><Radio :size="13" /> {{ c.data.presence.length }} live</span>
            <span v-if="routeInfo && routeInfo.distance != null" class="flex items-center gap-1 text-ink-dim" :title="routeInfo.mode === 'direct' ? 'Straight-line estimate — the routing service was unreachable' : 'Walking distance + on-foot ETA for the previewed agent’s route'">
                <Footprints :size="13" :style="{ color: routeColor || undefined }" />
                {{ fmtDistance(routeInfo.distance) }}<span v-if="routeInfo.duration != null"> · {{ fmtDuration(routeInfo.duration) }}</span><span v-if="routeInfo.mode === 'direct'" class="text-ink-faint"> direct</span>
            </span>
            <button v-if="canOptimize" @click="optimize = !optimize" class="inline-flex items-center gap-1" :class="optimize ? 'text-accent' : 'text-ink-faint'"
                title="Optimize walk — reorder the previewed agent’s farm/capture stops for the shortest walk (link throws stay in fielding order)">
                <Route :size="13" /> {{ optimize ? 'optimized' : 'optimize walk' }}
            </button>
            <button v-if="c.editable" @click="c.dropMode.value = !c.dropMode.value" class="inline-flex items-center gap-1" :class="c.dropMode.value ? 'text-accent' : 'text-ink-faint'">
                <Crosshair :size="13" /> {{ c.dropMode.value ? 'tap to drop' : 'map-drop' }}
            </button>
            <button @click="c.share.toggle()" class="inline-flex items-center gap-1" :class="c.share.sharing.value ? 'text-accent' : 'text-ink-faint'" title="opt-in live location">
                <Navigation :size="13" /> {{ c.share.sharing.value ? 'sharing' : 'share loc' }}
            </button>
        </div>
        <div class="flex-1 min-h-0">
            <OpMap :op-id="c.data.op.id" :waypoints="c.data.waypoints" :presence="presence" :statuses="statuses"
                :selection="c.selection.value" :links="planLinks" :route-ids="routeIds" :route-color="routeColor" :editable="c.editable"
                @select="onSelect" @drop="onBackground" @route-info="routeInfo = $event" @add-catalog="c.addFromCatalog" />
        </div>
    </div>
</template>
