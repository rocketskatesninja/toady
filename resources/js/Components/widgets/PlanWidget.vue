<script setup>
import { inject, ref, reactive, computed, watch, onMounted, nextTick } from 'vue';
import { usePage } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import { HOLD_MS } from '@/drag';
import { useSelectionSync } from '@/useSelection';
import { Navigation, Copy, Import, CheckCircle2, MapPin, Lock, Anchor, Check, Plus, KeyRound, ListOrdered, ArrowDownAZ, ChevronDown, ChevronRight } from 'lucide-vue-next';
import { haversineMeters, fmtDistance } from '@/geo';
import { lsGet, lsSet } from '@/ls';
import { ACTIONS, hasDetail, catalogSearch } from '@/plan';
import { ui, up } from '@/ui';
import PlanCard from '@/Components/plan/PlanCard.vue';
import ActionDetail from '@/Components/plan/ActionDetail.vue';

const c = inject('opctx');
const page = usePage();

// Operatives planning an op edit in place; everyone else — and any active op — sees the read-only
// checklist. `building` (op status, via c.editable) is the single gate for every edit control.
const building = computed(() => c.editable);

// ── one portal = one card: keys + intel + directives, in route order ────────────────────────────────────
function buildGroup(w, seq) {
    const tasks = c.data.steps.filter((s) => s.op_waypoint_id === w.id);
    const done = tasks.filter((t) => t.done).length;
    const holders = c.data.keyHoldings.filter((h) => h.op_waypoint_id === w.id);
    const held = holders.reduce((s, h) => s + h.qty, 0);
    const needed = w.keys_needed || 0;
    return {
        key: String(w.id), wp: w, seq, placed: w.lat != null,
        tasks, done, allDone: tasks.length > 0 && done === tasks.length,
        mine: tasks.filter((t) => t.assignee_id === c.me).length,
        needed, held, short: Math.max(0, needed - held), holders, myKeys: holders.find((h) => h.user_id === c.me)?.qty ?? 0,
    };
}
const groups = computed(() => c.data.waypoints.map((w, i) => buildGroup(w, i + 1)).filter((g) => g.tasks.length || building.value));

// anchors numbered by seq (mirrors how templates encode a link target as "Anchor 1/2")
const anchorNo = computed(() => {
    const m = {};
    [...c.data.waypoints].filter((w) => w.role === 'anchor').sort((a, b) => a.seq - b.seq).forEach((w, i) => { m[w.id] = i + 1; });
    return m;
});
// every portal as a link target: "6. 1787 Constitution Oak (A2)"
const targets = computed(() => c.data.waypoints.map((w, i) => ({
    id: w.id, placed: w.lat != null,
    label: `${i + 1}. ${w.title || 'untitled'}${anchorNo.value[w.id] ? ` (A${anchorNo.value[w.id]})` : ''}`,
})));

// ── list sort: as-planned (the seq / drag order), by name, or by distance ────────────────────────────
// View-only — it never touches seq, so map numbers, links, and sequential locking are unaffected. Available
// to EVERYONE, in planning AND active ops. Remembered per op (per device).
const SORT_KEY = `toady-plan-sort:${c.data.op.id}`;
const sortMode = ref(['name', 'dist'].includes(lsGet(SORT_KEY)) ? lsGet(SORT_KEY) : 'planned');
watch(sortMode, (m) => lsSet(SORT_KEY, m));
const SORTS = [
    { k: 'planned', icon: ListOrdered, label: 'As planned' },
    { k: 'name', icon: ArrowDownAZ, label: 'By name' },
    { k: 'dist', icon: Navigation, label: 'By distance' },
];
// Origin for the "distance" sort: your own live location if you're sharing it, else the first placed portal.
// Null when nothing's placed → distance sort just falls back to planned order. haversineMeters wants [lng,lat].
const distOrigin = computed(() => {
    const me = c.data.presence?.find((p) => p.user_id === c.me && p.lat != null);
    if (me) return [me.lng, me.lat];
    const first = c.data.waypoints.find((w) => w.lat != null);
    return first ? [first.lng, first.lat] : null;
});
const distTo = (g) => haversineMeters(distOrigin.value, [g.wp.lng, g.wp.lat]);
// name/distance sort a COPY (unplaced portals sink to the bottom); 'planned' is the canonical seq order
const viewGroups = computed(() => {
    const gs = groups.value;
    if (sortMode.value === 'name') return [...gs].sort((a, b) => (a.wp.title || '￿').localeCompare(b.wp.title || '￿', undefined, { sensitivity: 'base' }));
    if (sortMode.value === 'dist' && distOrigin.value) return [...gs].sort((a, b) => (a.placed ? distTo(a) : Infinity) - (b.placed ? distTo(b) : Infinity));
    return gs;
});
const distLabel = (g) => (sortMode.value === 'dist' && distOrigin.value && g.placed ? fmtDistance(distTo(g)) : null);

// op-wide key totals for the top strip
const totals = computed(() => {
    let needed = 0, held = 0;
    for (const g of groups.value) { needed += g.needed; held += Math.min(g.held, g.needed); }
    return { needed, held, short: Math.max(0, needed - held) };
});

// sequential ops (visible/hidden) run in portal order — lock later cards/directives until earlier ones clear
const sequential = computed(() => c.data.op.type === 'visible' || c.data.op.type === 'hidden');
function lockAfterFirstOpen(items, isOpen, keyOf) {
    const locked = new Set();
    if (!sequential.value || c.manage) return locked;
    let blocked = false;
    for (const it of items) { if (blocked) locked.add(keyOf(it)); else if (isOpen(it)) blocked = true; }
    return locked;
}
const lockedKeys = computed(() => lockAfterFirstOpen(groups.value, (g) => g.tasks.some((t) => !t.done), (g) => g.key));
const lockedSteps = computed(() => lockAfterFirstOpen(groups.value.flatMap((g) => g.tasks), (t) => !t.done, (t) => t.id));

// accordion — one card open at a time, synced to the map selection (no yank-scroll)
const { openId, toggle } = useSelectionSync(c, 'waypoint', 'pcard-', (id) => groups.value.some((g) => g.wp.id === id), false, () => c.focus !== 'plan');

// deep link from a notification (?step=ID): open that card, scroll to + flash the directive
const flashStep = ref(null);
function highlightFromUrl() {
    const id = Number(new URLSearchParams(page.url.split('?')[1] || '').get('step'));
    const step = id && c.data.steps.find((s) => s.id === id);
    if (!step) return;
    openId.value = step.op_waypoint_id;
    flashStep.value = id;
    nextTick(() => {
        document.getElementById('pstep-' + id)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => { flashStep.value = null; }, 2600);
    });
}
onMounted(highlightFromUrl);
watch(() => page.url, highlightFromUrl);

// drag-to-reorder cards (planning only, and only in as-planned order — a name/distance sort disables the
// drag so a view sort can't be mistaken for an edit to the plan's sequence)
const order = ref([]);
watch(viewGroups, (gs) => { order.value = [...gs]; }, { immediate: true });
const canDrag = computed(() => building.value && sortMode.value === 'planned');
function onReorder() { if (canDrag.value) c.reorderWaypoints(order.value.map((g) => g.wp.id)); }

// ── add / import / export portals (planning) ─────────────────────────────────────────────────────────
const addQuery = ref('');
const addResults = ref([]);
const searchAdd = catalogSearch(addQuery, addResults);
function submitAdd() { if (!addQuery.value.trim()) return; c.addLocation(addQuery.value); addQuery.value = ''; addResults.value = []; }
function pickPortal(p) { c.addFromCatalog(p); addQuery.value = ''; addResults.value = []; }
const showImport = ref(false);
const importText = ref('');
function doImport() { if (importText.value.trim()) c.importPlan(importText.value, () => { importText.value = ''; showImport.value = false; }); }
const iitcCopied = ref(false);
async function copyIitc() {
    try {
        const { data } = await window.axios.get(`/ops/${c.data.op.id}/export?format=iitc`);
        await navigator.clipboard.writeText(JSON.stringify(data));
        iitcCopied.value = true;
        setTimeout(() => { iitcCopied.value = false; }, 1500);
    } catch (e) { /* fetch or clipboard blocked */ }
}

// ── getting started (no directives yet): add portals → pick anchors → build the fan ─────────────────────
const thin = computed(() => building.value && c.data.steps.length === 0);
const anchorCount = computed(() => Object.keys(anchorNo.value).length);
const placedWaypoints = computed(() => c.data.waypoints.filter((w) => w.lat != null));
function toggleAnchor(w) { c.setRole(w, { target: { value: w.role === 'anchor' ? 'spine' : 'anchor' } }); }

// ── build tools: auto-fan · add to every portal · reset (collapsed state remembered per device) ─────
const TOOLS_KEY = 'toady-plan-tools';
const toolsOpen = ref(lsGet(TOOLS_KEY) === 'open');
watch(toolsOpen, (o) => lsSet(TOOLS_KEY, o ? 'open' : 'closed'));
const toolsShown = computed(() => thin.value || toolsOpen.value); // a fresh plan needs the fan, so it's always open

const FAN_MODES = ['links', 'keys', 'both'];
const fanMode = ref('both');
const fanAssignee = ref(null);

// "add to every portal": the same action · assignee · second field as a directive row, applied to all portals
// in one atomic bulk add (c.addTaskToAll keeps only the field that matches the action)
const bulk = reactive({ action: 'hack', assignee_id: null, links: [], mods: null, qty: null });
const DETAIL_LABEL = { link: 'Link to', mod: 'Mod', 'farm keys': 'Keys each' };
const placedTargets = computed(() => targets.value.filter((t) => t.placed));

const chip = 'h-8 min-w-0 flex items-center gap-1 rounded border px-2 text-xs font-mono';
</script>

<template>
    <div class="@container px-1.5 py-2 space-y-3">
        <!-- op is active → the plan is read-only; call it out at the very top -->
        <div v-if="c.manage && !c.editable" class="flex items-start gap-1.5 text-[11px] font-mono text-amber-300/80 daylight:text-amber-700 border border-amber-500/30 daylight:border-amber-600/40 rounded px-2 py-1.5"><Lock :size="13" class="shrink-0 mt-0.5" /> <span>Plan locked while the op is active — flip the status to planning to edit.</span></div>

        <!-- top strip: op-wide key total · list sort (everyone, planning AND active) -->
        <div v-if="totals.needed || groups.length > 1" class="flex items-center gap-3">
            <span v-if="totals.needed" class="inline-flex items-center gap-1.5 text-xs font-mono" :title="`${totals.held}/${totals.needed} keys held` + (totals.short ? ` · ${totals.short} short` : '')">
                <KeyRound :size="13" class="text-ink-faint" /><span :class="totals.short ? 'text-rose-400' : 'text-accent'">{{ totals.held }}/{{ totals.needed }} keys</span>
            </span>
            <div v-if="groups.length > 1" class="ml-auto h-8 inline-flex rounded border border-line overflow-hidden" title="sort the portal list">
                <button v-for="opt in SORTS" :key="opt.k" @click="sortMode = opt.k" type="button" :title="opt.label" class="w-9 flex items-center justify-center border-l border-line first:border-l-0"
                    :class="sortMode === opt.k ? 'bg-accent/15 text-accent' : 'text-ink-dim hover:text-accent'"><component :is="opt.icon" :size="14" /></button>
            </div>
        </div>

        <div v-if="c.data.op.hidden_waypoints" class="flex items-center gap-1.5 text-[11px] font-mono text-ink-dim border border-line rounded px-2 py-1.5"><Lock :size="13" class="shrink-0" /> {{ c.data.op.hidden_waypoints }} more portal{{ c.data.op.hidden_waypoints === 1 ? '' : 's' }} hidden — revealed as you advance.</div>

        <template v-if="building">
            <!-- add a portal (name / Intel link) · IITC import · IITC export -->
            <div class="relative">
                <div class="flex gap-1.5">
                    <input v-model="addQuery" @input="searchAdd" @keyup.enter="submitAdd" placeholder="name or Intel link…" :class="ui.input" />
                    <button @click="submitAdd" :class="ui.primary">add</button>
                    <button @click="showImport = !showImport" title="import an IITC field plan" :class="showImport ? ui.primary : ui.btn" class="w-8"><Import :size="15" class="shrink-0" /></button>
                    <button @click="copyIitc" :title="iitcCopied ? 'copied — paste into IITC → Draw Tools → Import Drawn Items' : 'copy the plan for IITC (Draw Tools)'" :class="ui.btn" class="w-8"><component :is="iitcCopied ? CheckCircle2 : Copy" :size="15" class="shrink-0" /></button>
                </div>
                <ul v-if="addResults.length" :class="ui.menu">
                    <li v-for="p in addResults" :key="p.id"><button @click="pickPortal(p)" :class="ui.menuItem"><MapPin :size="13" class="shrink-0" /><span class="truncate">{{ p.title }}</span><span v-if="p.status === 'unverified'" class="shrink-0 text-[9px] font-mono uppercase tracking-wide text-amber-400/80" title="only 1 source has this name">unverified</span><span v-if="p.gate_pin || p.parking" class="text-[10px] text-amber-400/80 shrink-0">◆</span></button></li>
                </ul>
            </div>
            <div v-if="showImport" class="space-y-1.5">
                <textarea v-model="importText" rows="3" placeholder="paste an IITC Draw Tools or Bookmarks export (JSON)…" :class="ui.area" class="font-mono"></textarea>
                <div class="flex items-center gap-2">
                    <span :class="ui.hint">portals → portals · links → link directives + key targets</span>
                    <button @click="doImport" :disabled="!importText.trim()" :class="ui.primary" class="ml-auto">import</button>
                </div>
            </div>

            <!-- getting started: a fresh plan with no directives yet -->
            <div v-if="thin" class="border border-line rounded-lg px-2.5 pt-3 pb-3.5 bg-gradient-to-b from-emerald-500/[0.04] to-transparent">
                <h4 :class="ui.sec">Getting started</h4>
                <ol class="space-y-3">
                    <li class="flex gap-2.5">
                        <span class="shrink-0 size-6 rounded grid place-items-center" :class="c.data.waypoints.length ? 'bg-accent text-accent-ink' : 'border border-line text-ink-faint'"><component :is="c.data.waypoints.length ? Check : Plus" :size="13" /></span>
                        <div class="min-w-0"><div class="text-xs font-mono uppercase tracking-wide text-ink">Add your portals</div><p :class="ui.hint"><span class="text-accent font-mono">{{ c.data.waypoints.length }} added</span> · search above, paste a link, or import IITC</p></div>
                    </li>
                    <li class="flex gap-2.5">
                        <span class="shrink-0 size-6 rounded grid place-items-center text-xs font-mono" :class="anchorCount ? 'bg-accent text-accent-ink' : 'border border-line text-ink-faint'">{{ anchorCount || 2 }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="text-xs font-mono uppercase tracking-wide text-ink">Pick your anchor(s)</div>
                            <p :class="ui.hint">Tap the 1–2 portals you'll link <span class="text-ink">from</span>.</p>
                            <div v-if="placedWaypoints.length" class="grid grid-cols-2 @md:grid-cols-3 gap-1.5 mt-2">
                                <button v-for="w in placedWaypoints" :key="w.id" @click="toggleAnchor(w)" :class="[chip, w.role === 'anchor' ? 'text-amber-300 border-amber-400/45 bg-amber-400/10' : 'text-ink-dim border-line hover:text-ink hover:border-accent/40']">
                                    <Anchor :size="12" :class="w.role === 'anchor' ? 'opacity-100' : 'opacity-50'" /><span class="flex-1 min-w-0 truncate text-left">{{ w.title || 'untitled' }}</span><span v-if="anchorNo[w.id]" class="text-[10px] uppercase">A{{ anchorNo[w.id] }}</span>
                                </button>
                            </div>
                            <p v-else class="text-[11px] font-mono text-ink-faint mt-1.5">Add at least one placed portal first.</p>
                        </div>
                    </li>
                    <li class="flex gap-2.5">
                        <span class="shrink-0 size-6 rounded grid place-items-center border border-line text-ink-faint text-xs font-mono">3</span>
                        <div class="min-w-0"><div class="text-xs font-mono uppercase tracking-wide text-ink">Build the fan</div><p :class="ui.hint">Pick what to make under Auto-fan below, then tap Build the fan.</p></div>
                    </li>
                </ol>
            </div>

            <!-- build tools -->
            <div class="border border-line rounded-lg overflow-hidden">
                <button @click="toolsOpen = !toolsOpen" :disabled="thin" class="w-full h-9 flex items-center gap-2 px-2.5 text-left bg-emerald-500/5 enabled:hover:bg-emerald-500/10">
                    <component :is="toolsShown ? ChevronDown : ChevronRight" :size="14" class="shrink-0 text-accent" />
                    <span class="shrink-0 text-[10px] font-mono uppercase tracking-[0.16em] text-accent">Build tools</span>
                    <span v-if="!toolsShown" class="ml-auto truncate text-[10px] font-mono uppercase tracking-wide text-ink-faint">auto-fan · add to all · reset</span>
                </button>
                <div v-if="toolsShown" class="border-t border-line/60 px-2.5 pt-3 pb-3.5 space-y-4">
                    <section>
                        <h4 :class="ui.sec">Auto-fan</h4>
                        <p :class="ui.hint" class="mb-2">Draws link directives and/or key targets from your anchor(s) to every portal.</p>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <span :class="ui.label">Make</span>
                                <div :class="ui.seg"><button v-for="m in FAN_MODES" :key="m" @click="fanMode = m" type="button" :class="ui.segBtn(fanMode === m)">{{ m }}</button></div>
                            </div>
                            <label>
                                <span :class="ui.label">Assign to</span>
                                <select v-model="fanAssignee" :class="ui.select"><option :value="null">ANYONE</option><option v-for="p in c.data.participants" :key="p.user_id" :value="p.user_id">{{ up(p.callsign) }}</option></select>
                            </label>
                        </div>
                        <button @click="c.autoFan(fanMode, fanAssignee)" :disabled="!c.canFan" :title="c.canFan ? 'Build the fan from your anchor(s)' : 'Needs 2 placed anchors + 1 other portal, or 1 anchor + 2 others'" :class="ui.primary" class="mt-2 w-full">Build the fan</button>
                    </section>

                    <section v-if="groups.length > 1">
                        <h4 :class="ui.sec">Add to every portal</h4>
                        <div class="grid grid-cols-2 gap-2">
                            <label>
                                <span :class="ui.label">Action</span>
                                <select v-model="bulk.action" :class="ui.select"><option v-for="a in ACTIONS" :key="a" :value="a">{{ up(a) }}</option></select>
                            </label>
                            <label>
                                <span :class="ui.label">Assign to</span>
                                <select v-model="bulk.assignee_id" :class="ui.select"><option :value="null">ANYONE</option><option v-for="p in c.data.participants" :key="p.user_id" :value="p.user_id">{{ up(p.callsign) }}</option></select>
                            </label>
                        </div>
                        <div v-if="hasDetail(bulk.action)" class="mt-2">
                            <span :class="ui.label">{{ DETAIL_LABEL[bulk.action] }}</span>
                            <ActionDetail :action="bulk.action" :links="bulk.links" :mods="bulk.mods" :qty="bulk.qty" :targets="placedTargets" @update="(p) => Object.assign(bulk, p)" />
                        </div>
                        <button @click="c.addTaskToAll(bulk)" :disabled="bulk.action === 'link' && !bulk.links.length" :class="ui.btn" class="mt-2 w-full">+ add to every portal</button>
                    </section>

                    <section>
                        <h4 :class="ui.sec">Reset</h4>
                        <p :class="ui.hint" class="mb-2">Delete everything of one kind across the plan — you can undo it.</p>
                        <div class="flex gap-2">
                            <button @click="c.clearWaypoints" :disabled="!c.data.waypoints.length" :class="ui.danger" class="flex-1" title="Delete every portal and its directives">portals</button>
                            <button @click="c.clearDirectives" :disabled="!c.data.steps.length" :class="ui.danger" class="flex-1" title="Delete every directive — the portals stay">directives</button>
                            <button @click="c.clearKeys" :disabled="!c.data.keyHoldings.length" :class="ui.danger" class="flex-1" title="Clear every agent’s held-key counts — key targets stay">keys</button>
                        </div>
                    </section>
                </div>
            </div>
        </template>

        <!-- portal cards -->
        <draggable v-model="order" item-key="key" tag="div" handle=".plan-handle" :delay="HOLD_MS" :touchStartThreshold="8" :animation="160" ghost-class="mission-ghost" drag-class="mission-drag" :disabled="!canDrag" @end="onReorder">
            <template #item="{ element: g }">
                <div class="mission-slot mb-2">
                    <PlanCard :g="g" :open="openId === g.wp.id" :locked="lockedKeys.has(g.key)" :dist="distLabel(g)" :can-drag="canDrag"
                        :locked-steps="lockedSteps" :flash-step="flashStep" :targets="targets" @toggle="toggle(g.wp.id)" />
                </div>
            </template>
        </draggable>
        <p v-if="!groups.length" class="text-center text-ink-faint text-xs py-4">{{ building ? 'Add a portal above to start the mission.' : 'No directives yet.' }}</p>
    </div>
</template>
