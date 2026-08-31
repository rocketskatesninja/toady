<script setup>
import { inject, ref, reactive, computed, watch, onMounted, nextTick } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import { HOLD_MS } from '@/drag';
import { useSelectionSync } from '@/useSelection';
import { Navigation, Link2, Copy, Import, CheckCircle2, MapPin, X, Pickaxe, TriangleAlert, Lock, Star, Anchor, ChevronUp, ChevronDown, KeyRound, Check, Plus, ShieldCheck, ShieldQuestion, Flag, ListOrdered, ArrowDownAZ, Eye } from 'lucide-vue-next';

// shared-catalog trust badge for a placed portal's name (from wp.catalog_status)
const CAT_BADGE = {
    verified: { label: 'verified', cls: 'text-accent', icon: ShieldCheck },
    owner_locked: { label: 'verified', cls: 'text-accent', icon: ShieldCheck },
    unverified: { label: 'unverified', cls: 'text-amber-400/90', icon: ShieldQuestion },
    hidden: { label: 'disputed', cls: 'text-rose-400', icon: Flag },
};
import { roleIcon, roleColor } from '@/roles';
import { factionChip } from '@/faction';
import { hacksForKeys, burnoutsFor, HACKS_BEFORE_BURNOUT, farmMinutes } from '@/mechanics';
import { fmtDuration, haversineMeters, fmtDistance } from '@/geo';
import { lsGet, lsSet } from '@/ls';
import WaypointIntel from '@/Components/WaypointIntel.vue';

const c = inject('opctx');
const page = usePage();

// Operatives planning an op edit in place; everyone else — and any active op — sees the read-only
// checklist. `building` (op status, via c.editable) is the single gate for every edit control.
const building = computed(() => c.editable);

// shared field styling
const fld = 'bg-inset border border-line rounded px-1.5 py-1.5 text-sm focus:border-accent focus:outline-none';
const sel = 'bg-inset border border-line rounded px-1 py-0.5 font-mono focus:border-accent focus:outline-none';
const menu = 'absolute left-0 right-0 z-20 mt-1 bg-surface border border-line rounded shadow-xl max-h-52 overflow-auto op-scroll';
const menuItem = 'w-full text-left flex items-center gap-1.5 px-1.5 py-1.5 text-sm text-ink-dim hover:bg-emerald-500/10 hover:text-ink';

// objectives — the four common ones up front, the rest behind "more"
const COMMON = ['link', 'deploy', 'farm keys', 'hack'];
const MORE = ['frack', 'capture', 'destroy', 'ada', 'jarvis', 'mod', 'recharge', 'photo', 'passphrase', 'move', 'note'];
const ACTIONS = [...COMMON, ...MORE];
const MODS = ['Shield', 'Aegis Shield', 'Heat Sink', 'Multi-hack', 'Force Amp', 'Turret', 'Link Amp', 'Ultra Link', 'Transmuter'];

// ── farming effort for a key shortfall (per-portal + op total) ────────────────
function farmEst(short) {
    if (short <= 0) return null;
    const { lo, hi } = hacksForKeys(short);
    return { lo, hi, grind: fmtDuration(farmMinutes(lo) * 60), burnouts: burnoutsFor(lo), multiBurn: lo > HACKS_BEFORE_BURNOUT };
}
const rng = (lo, hi) => (lo === hi ? `${lo}` : `${lo}–${hi}`);

// ── one location = one card: keys + intel + directives, in route order ────────
function buildGroup(w, seq) {
    const tasks = c.data.steps.filter((s) => s.op_waypoint_id === w.id);
    const done = tasks.filter((t) => t.done).length;
    const hs = c.data.keyHoldings.filter((h) => h.op_waypoint_id === w.id);
    const held = hs.reduce((s, h) => s + h.qty, 0);
    const needed = w.keys_needed || 0;
    const short = Math.max(0, needed - held);
    const placed = w.lat != null;
    return {
        key: String(w.id), wp: w, seq, placed, role: w.role,
        tasks, done, allDone: tasks.length > 0 && done === tasks.length,
        mine: tasks.filter((t) => t.assignee_id === c.me).length,
        needed, held, short, holders: hs, myKeys: hs.find((h) => h.user_id === c.me)?.qty ?? 0,
        est: farmEst(short),
        nav: placed ? `https://www.google.com/maps/dir/?api=1&destination=${w.lat},${w.lng}` : null,
        street: placed ? `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${w.lat},${w.lng}` : null,
        intel: placed ? `https://intel.ingress.com/?pll=${w.lat},${w.lng}` : null,
    };
}
const groups = computed(() => c.data.waypoints.map((w, i) => buildGroup(w, i + 1)).filter((g) => g.tasks.length || building.value));

// ── list sort: as-planned (the seq / drag order), by name, or by distance ──────
// View-only — it never touches seq, so map numbers, links, and sequential locking are unaffected. Available
// to EVERYONE, in planning AND active ops. Remembered per op (per device).
const SORT_KEY = `toady-plan-sort:${c.data.op.id}`;
const savedSort = lsGet(SORT_KEY);
const sortMode = ref(['name', 'dist'].includes(savedSort) ? savedSort : 'planned');
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

// The displayed order. name/distance sort a COPY (unplaced portals sink to the bottom); 'planned' is the
// canonical seq order and the only mode where drag-to-reorder is live.
const viewGroups = computed(() => {
    const gs = groups.value;
    if (sortMode.value === 'name') {
        return [...gs].sort((a, b) => (a.wp.title || '￿').localeCompare(b.wp.title || '￿', undefined, { sensitivity: 'base' }));
    }
    if (sortMode.value === 'dist' && distOrigin.value) {
        return [...gs].sort((a, b) => (a.placed ? distTo(a) : Infinity) - (b.placed ? distTo(b) : Infinity));
    }
    return gs;
});
// per-row distance chip, shown only while sorting by distance
function distLabel(g) {
    return sortMode.value === 'dist' && distOrigin.value && g.placed ? fmtDistance(distTo(g)) : null;
}

// op-wide key + farm totals for the header strip
const totals = computed(() => {
    let needed = 0, held = 0, lo = 0, hi = 0;
    for (const g of groups.value) { needed += g.needed; held += Math.min(g.held, g.needed); if (g.est) { lo += g.est.lo; hi += g.est.hi; } }
    return { needed, held, short: Math.max(0, needed - held), lo, hi };
});

// sequential ops (visible/hidden) run in waypoint order — lock later cards/steps until earlier ones clear
const sequential = computed(() => c.data.op.type === 'visible' || c.data.op.type === 'hidden');
const lockedKeys = computed(() => {
    const locked = new Set();
    if (!sequential.value || c.manage) return locked;
    let blocked = false;
    for (const g of groups.value) { if (blocked) locked.add(g.key); else if (g.tasks.some((t) => !t.done)) blocked = true; }
    return locked;
});
const lockedSteps = computed(() => {
    const locked = new Set();
    if (!sequential.value || c.manage) return locked;
    let blocked = false;
    for (const g of groups.value) for (const t of g.tasks) { if (blocked) locked.add(t.id); else if (!t.done) blocked = true; }
    return locked;
});

// accordion — one card open at a time, synced to the map selection (no yank-scroll)
const { openId, toggle } = useSelectionSync(c, 'waypoint', 'pcard-', (id) => groups.value.some((g) => g.wp.id === id), false, () => c.focus !== 'plan');

// deep link from a notification (?step=ID): open that card, scroll to + flash the directive
const flashStep = ref(null);
function highlightFromUrl() {
    const id = Number(new URLSearchParams(page.url.split('?')[1] || '').get('step'));
    if (!id) return;
    const step = c.data.steps.find((s) => s.id === id);
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

// drag-to-reorder location cards (build only, and only in as-planned order — sorting by name/distance
// disables the drag so a view sort can't be mistaken for an edit to the plan's sequence)
const order = ref([]);
watch(viewGroups, (gs) => { order.value = [...gs]; }, { immediate: true });
const canDrag = computed(() => building.value && sortMode.value === 'planned');
function onReorder() { if (canDrag.value) c.reorderWaypoints(order.value.map((g) => g.wp.id)); }

// inline rename of a location — only when its card is expanded AND the op is
// in planning (building). Click the name → input; Enter/blur saves, Esc cancels.
const editingId = ref(null);
const editTitle = ref('');
function startRename(g) {
    if (!building.value || openId.value !== g.wp.id) return;
    editingId.value = g.wp.id;
    editTitle.value = g.wp.title || '';
    nextTick(() => { const el = document.getElementById('wpname-' + g.wp.id); if (el) { el.focus(); el.select(); } });
}
function onNameClick(g, ev) {
    // Only intercept (and rename) once the card is open + editable; otherwise
    // let the click bubble to the header so it toggles the card as usual.
    if (building.value && openId.value === g.wp.id) { ev.stopPropagation(); startRename(g); }
}
function onNameKeydown(g, ev) {
    ev.stopPropagation(); // keep Space/Enter from reaching the header's toggle handler
    if (ev.key === 'Enter') { ev.preventDefault(); saveRename(g); }
    else if (ev.key === 'Escape') { ev.preventDefault(); cancelRename(); }
}
function saveRename(g) {
    if (editingId.value !== g.wp.id) return; // already closed (e.g. Esc then blur)
    editingId.value = null;
    const t = editTitle.value.trim();
    if (t !== (g.wp.title || '')) c.renameWaypoint(g.wp, t);
}
function cancelRename() { editingId.value = null; }

// anchors numbered by seq (mirrors how templates encode a link target as "Anchor 1/2")
const anchorNo = computed(() => {
    const m = {};
    [...c.data.waypoints].filter((w) => w.role === 'anchor').sort((a, b) => a.seq - b.seq).forEach((w, i) => { m[w.id] = i + 1; });
    return m;
});
const anchorCount = computed(() => Object.keys(anchorNo.value).length);

// ── guided setup (thin op) — add portals → pick anchors → build the fan ───────
const thin = computed(() => building.value && c.data.steps.length === 0);
const placedWaypoints = computed(() => c.data.waypoints.filter((w) => w.lat != null)); // portals with coords: anchor picker + bulk link target
function toggleAnchor(w) { c.setRole(w, { target: { value: w.role === 'anchor' ? 'spine' : 'anchor' } }); }

const fanMode = ref('both');
const assignFieldTo = ref(null);
const showOpts = ref(false);
const fanModes = [
    { v: 'links', l: 'links' }, { v: 'keys', l: 'keys' }, { v: 'both', l: 'both' },
];

// ── add / import portals (build) ──────────────────────────────────────────────
// Debounced catalog lookup that writes into a results ref — shared by the add
// box and the per-card "set portal" attach box (same endpoint + 200ms debounce).
function catalogSearch(queryRef, resultsRef) {
    let timer;
    return () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const q = queryRef.value.trim();
            if (q.length < 2) { resultsRef.value = []; return; }
            const { data } = await window.axios.get('/api/catalog/search', { params: { q } });
            resultsRef.value = data;
        }, 200);
    };
}
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

// ── directive helpers ─────────────────────────────────────────────────────────
const moreOpen = reactive({}); // per-card: reveal the extra objectives

// "add to every portal" composer (build): mirrors a directive row — action, then the matching detail
// (link target / mod / key count), then the assignee — applied to all portals in one atomic bulk add.
const bulkAction = ref('hack');
const bulkTarget = ref(null); // link → the portal every other portal throws to
const bulkMods = ref('');
const bulkQty = ref(null);
const bulkAssignee = ref(null);
function addToEveryPortal() {
    const draft = { action: bulkAction.value, assignee_id: bulkAssignee.value };
    if (bulkAction.value === 'link') {
        if (!bulkTarget.value) return;
        draft.links = [bulkTarget.value];
    } else if (bulkAction.value === 'mod' && bulkMods.value) {
        draft.mods = bulkMods.value;
    } else if (bulkAction.value === 'farm keys' && bulkQty.value) {
        draft.qty = +bulkQty.value;
    }
    c.addTaskToAll(draft);
}
function linkTargets(g) { return c.data.waypoints.map((w, i) => ({ ...w, n: i + 1 })).filter((w) => w.id !== g.wp.id); }
function secondDirective(s) {
    if (s.action === 'link' && s.links?.length) return '→ ' + (c.data.waypoints.find((w) => w.id === s.links[0])?.title || 'portal');
    if (s.action === 'mod' && s.mods) return 'w/ ' + s.mods;
    if (s.action === 'farm keys' && s.qty) return '× ' + s.qty;
    return '';
}
function moveStep(s, g, dir) {
    const i = g.tasks.findIndex((t) => t.id === s.id);
    const j = i + dir;
    if (j < 0 || j >= g.tasks.length) return;
    const ids = g.tasks.map((t) => t.id);
    [ids[i], ids[j]] = [ids[j], ids[i]];
    c.reorderSteps(groups.value.flatMap((grp) => (grp.key === g.key ? ids : grp.tasks.map((t) => t.id))));
}

// objective templates
const savingTpl = ref(null);
const tplName = ref('');
function startSaveTpl(wpId) { savingTpl.value = wpId; tplName.value = ''; }
function confirmSaveTpl(g) {
    const name = tplName.value.trim();
    if (!name || !g.tasks.length) return;
    c.saveTemplate(name, g.wp.id);
    savingTpl.value = null; tplName.value = '';
}

// attach a real portal to a generic (unplaced) location
const attaching = ref(null);
const attachQuery = ref('');
const attachResults = ref([]);
const searchAttach = catalogSearch(attachQuery, attachResults);
function doAttach(wp, p) { c.attachPortal(wp, p.id); attaching.value = null; }

// copy a portal's intel link
const copied = ref(null);
async function copyIntel(g) {
    if (!g.intel) return;
    try {
        await navigator.clipboard.writeText(g.intel);
        copied.value = g.wp.id;
        setTimeout(() => { if (copied.value === g.wp.id) copied.value = null; }, 1500);
    } catch (e) { /* clipboard blocked */ }
}
</script>

<template>
    <div class="px-1.5 py-2">
        <!-- op is active → the plan is read-only; call it out at the very top of the card -->
        <div v-if="c.manage && !c.editable" class="mb-2 flex items-start gap-1.5 text-[11px] font-mono text-amber-300/80 daylight:text-amber-700 border border-amber-500/30 daylight:border-amber-600/40 rounded px-1.5 py-1.5"><Lock :size="13" class="shrink-0 mt-0.5" /> <span>Plan locked while the op is active — flip the status to planning to edit.</span></div>
        <!-- top strip: op-wide key + farm totals, with the list sort anchored right -->
        <div v-if="totals.needed || totals.lo || groups.length > 1" class="mb-2 flex items-center gap-3 flex-wrap">
            <span v-if="totals.needed" class="inline-flex items-center gap-1.5 text-xs font-mono" :title="`${totals.held}/${totals.needed} keys held` + (totals.short ? ` · ${totals.short} short` : '')">
                <span class="inline-flex items-center gap-1 text-[10px] uppercase tracking-wide text-ink-faint"><KeyRound :size="12" /></span>
                <span :class="totals.short ? 'text-rose-400' : 'text-accent'">{{ totals.held }}/{{ totals.needed }}</span>
            </span>
            <span v-if="totals.lo" class="inline-flex items-center gap-1 text-[11px] font-mono text-ink-dim" :title="`≈${rng(totals.lo, totals.hi)} hacks to farm every short key (spread across the team)`">
                <Pickaxe :size="11" class="text-ink-faint" /> ≈{{ rng(totals.lo, totals.hi) }} hacks
            </span>
            <!-- list sort — view-only order; everyone, planning AND active ops -->
            <div v-if="groups.length > 1" class="ml-auto flex items-center gap-1.5 text-[10px] font-mono">
                <div class="inline-flex rounded border border-line overflow-hidden" title="sort the portal list">
                <button v-for="opt in SORTS" :key="opt.k" @click="sortMode = opt.k" type="button" :title="opt.label"
                    class="px-2 py-1 flex items-center justify-center border-l border-line first:border-l-0"
                    :class="sortMode === opt.k ? 'bg-accent/15 text-accent' : 'text-ink-dim hover:text-accent'">
                    <component :is="opt.icon" :size="13" />
                </button>
                </div>
            </div>
        </div>

        <div v-if="c.data.op.hidden_waypoints" class="mb-2 flex items-center gap-1.5 text-[11px] font-mono text-ink-dim border border-line rounded px-1.5 py-1.5"><Lock :size="13" class="shrink-0" /> {{ c.data.op.hidden_waypoints }} more waypoint{{ c.data.op.hidden_waypoints === 1 ? '' : 's' }} hidden — revealed as you advance.</div>

        <!-- ── build tools: add portals + build the fan ── -->
        <div v-if="building">
            <!-- add a location (name / Intel link) + IITC import / export -->
            <div class="relative mb-2">
                <div class="flex gap-2">
                    <input v-model="addQuery" @input="searchAdd" @keyup.enter="submitAdd" placeholder="portal name, or paste an Intel/scanner link…" :class="[fld, 'flex-1 min-w-0']" />
                    <button @click="submitAdd" class="bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-sm font-semibold rounded px-2 shrink-0">add</button>
                    <button @click="showImport = !showImport" title="import an IITC field plan" :class="showImport ? 'border-accent text-accent' : 'border-line text-ink-dim hover:text-accent'" class="border rounded px-2 shrink-0 flex items-center justify-center"><Import :size="16" /></button>
                    <button @click="copyIitc" :title="iitcCopied ? 'copied — paste into IITC → Draw Tools → Import Drawn Items' : 'copy the plan for IITC (Draw Tools) to the clipboard'" class="border border-line rounded px-2 shrink-0 flex items-center justify-center text-ink-dim hover:text-accent hover:border-accent"><component :is="iitcCopied ? CheckCircle2 : Copy" :size="16" /></button>
                </div>
                <ul v-if="addResults.length" :class="menu">
                    <li v-for="p in addResults" :key="p.id"><button @click="pickPortal(p)" :class="menuItem"><MapPin :size="13" class="shrink-0" /><span class="truncate">{{ p.title }}</span><span v-if="p.status === 'unverified'" class="shrink-0 text-[9px] font-mono uppercase tracking-wide text-amber-400/80" title="only 1 source has this name">unverified</span><span v-if="p.gate_pin || p.parking" class="text-[10px] text-amber-400/80 shrink-0">◆</span></button></li>
                </ul>
            </div>
            <div v-if="showImport" class="mb-2 space-y-1.5">
                <textarea v-model="importText" rows="3" placeholder="paste an IITC Draw Tools or Bookmarks export (JSON)…" class="w-full bg-inset border border-line rounded px-1.5 py-1.5 text-xs font-mono focus:border-accent focus:outline-none resize-y"></textarea>
                <div class="flex items-center gap-2">
                    <span class="text-[10px] text-ink-faint">portals → waypoints · links → directives + key needs</span>
                    <button @click="doImport" :disabled="!importText.trim()" class="ml-auto bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-xs font-semibold rounded px-2 py-1 disabled:opacity-40">import</button>
                </div>
            </div>

            <!-- guided fan (thin op only): pick anchors → build -->
            <div v-if="thin" class="mb-3 border border-line rounded-lg overflow-hidden bg-gradient-to-b from-emerald-500/[0.04] to-transparent">
                <div class="px-2 py-1.5 text-[10px] font-mono uppercase tracking-[0.16em] text-accent border-b border-line/60">▸ Getting started</div>
                <div class="px-2 py-2 space-y-2.5">
                    <!-- step 1 -->
                    <div class="flex gap-2.5">
                        <span class="shrink-0 w-5 h-5 rounded grid place-items-center" :class="c.data.waypoints.length ? 'bg-accent text-accent-ink' : 'border border-line text-ink-faint'"><component :is="c.data.waypoints.length ? Check : Plus" :size="12" /></span>
                        <div class="min-w-0"><div class="text-[11px] font-mono uppercase tracking-wide text-ink">Add your portals</div><div class="text-xs text-ink-dim mt-0.5"><span class="text-accent font-mono">{{ c.data.waypoints.length }} loaded</span> · import IITC · paste a link · search the catalog</div></div>
                    </div>
                    <!-- step 2 -->
                    <div class="flex gap-2.5 border-t border-line/50 pt-2.5">
                        <span class="shrink-0 w-5 h-5 rounded grid place-items-center font-mono text-[11px]" :class="anchorCount ? 'bg-accent text-accent-ink' : 'border border-line text-ink-faint'">{{ anchorCount || 2 }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] font-mono uppercase tracking-wide text-ink">Pick your anchor(s)</div>
                            <div class="text-xs text-ink-dim mt-0.5">Tap the 1–2 portals you'll link <span class="text-ink">from</span>.</div>
                            <div v-if="placedWaypoints.length" class="flex flex-wrap gap-1.5 mt-2">
                                <button v-for="w in placedWaypoints" :key="w.id" @click="toggleAnchor(w)" :class="['inline-flex items-center gap-1 text-[11px] font-mono border rounded px-2 py-1', w.role === 'anchor' ? 'text-amber-300 border-amber-400/45 bg-amber-400/10' : 'text-ink-dim border-line hover:text-ink hover:border-accent/40']">
                                    <Anchor :size="12" :class="w.role === 'anchor' ? 'opacity-100' : 'opacity-50'" /><span class="truncate max-w-[9rem]">{{ w.title || 'untitled' }}</span><span v-if="anchorNo[w.id]" class="text-[9px] uppercase">A{{ anchorNo[w.id] }}</span>
                                </button>
                            </div>
                            <div v-else class="text-[11px] font-mono text-ink-faint mt-1.5">Add at least one placed portal first.</div>
                        </div>
                    </div>
                    <!-- step 3 -->
                    <div class="flex gap-2.5 border-t border-line/50 pt-2.5">
                        <span class="shrink-0 w-5 h-5 rounded grid place-items-center border border-line text-ink-faint font-mono text-[11px]">3</span>
                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] font-mono uppercase tracking-wide text-ink">Build the fan</div>
                            <div class="flex items-center gap-2 mt-1.5">
                                <button @click="c.autoFan(fanMode, assignFieldTo)" :disabled="!c.canFan" :title="c.canFan ? 'From your anchor(s), build the fan' : 'Needs 2 placed anchors + 1 other portal, or 1 anchor + 2 others'" class="inline-flex items-center gap-1.5 bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-xs font-semibold rounded px-3 py-1.5 disabled:opacity-40 disabled:cursor-not-allowed">Auto</button>
                                <button @click="showOpts = !showOpts" class="text-[10px] font-mono uppercase tracking-wide text-ink-faint hover:text-accent px-1">{{ showOpts ? 'fewer options' : 'options' }}</button>
                            </div>
                            <div class="text-xs text-ink-dim mt-1.5">One tap — a link plus a farm-keys directive at every location, left open to the team.</div>
                            <div v-if="showOpts" class="mt-2 pt-2 border-t border-line/60 space-y-2">
                                <div class="flex items-center gap-2.5">
                                    <span class="text-[10px] font-mono uppercase tracking-wide text-ink-faint w-14 shrink-0">generate</span>
                                    <div class="inline-flex rounded border border-line overflow-hidden">
                                        <button v-for="m in fanModes" :key="m.v" @click="fanMode = m.v" :class="['text-[10px] font-mono uppercase tracking-wide px-2 py-0.5 border-r border-line last:border-r-0', fanMode === m.v ? 'bg-accent/15 text-accent' : 'text-ink-faint hover:text-ink-dim']">{{ m.l }}</button>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2.5">
                                    <span class="text-[10px] font-mono uppercase tracking-wide text-ink-faint w-14 shrink-0">assign to</span>
                                    <select v-model="assignFieldTo" :class="[sel, 'text-[10px] uppercase text-accent/80']"><option :value="null">anyone</option><option v-for="p in c.data.participants" :key="p.user_id" :value="p.user_id">{{ p.callsign }}</option></select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- compact fan/clear toolbar once a plan exists -->
            <div v-else class="mb-3 flex flex-wrap items-center gap-2">
                <button @click="c.autoFan(fanMode, assignFieldTo)" :disabled="!c.canFan" :title="c.canFan ? 'Rebuild the fan from your anchor(s)' : 'Needs 2 placed anchors + 1 other portal, or 1 anchor + 2 others'" class="text-[10px] font-mono uppercase tracking-wide border border-line rounded px-1.5 py-0.5 text-ink-dim hover:text-accent hover:border-accent disabled:opacity-40 disabled:cursor-not-allowed">Auto</button>
                <div class="inline-flex rounded border border-line overflow-hidden">
                    <button v-for="m in fanModes" :key="m.v" @click="fanMode = m.v" :class="['text-[10px] font-mono uppercase tracking-wide px-1.5 py-0.5 border-r border-line last:border-r-0', fanMode === m.v ? 'bg-accent/15 text-accent' : 'text-ink-faint hover:text-ink-dim']">{{ m.l }}</button>
                </div>
                <select v-model="assignFieldTo" title="who to auto-assign the generated directives to" :class="[sel, 'w-24 text-[10px] uppercase text-ink-dim']"><option :value="null">anyone</option><option v-for="p in c.data.participants" :key="p.user_id" :value="p.user_id">{{ p.callsign }}</option></select>
                <!-- spacer + delete controls, set apart from the auto settings -->
                <div class="ml-auto flex items-center gap-1.5 pl-2.5 border-l border-line/60">
                    <span class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">delete all</span>
                    <button v-if="c.data.waypoints.length" @click="c.clearWaypoints" title="Delete every portal and its directives" class="text-[10px] font-mono uppercase tracking-wide border border-line rounded px-1.5 py-0.5 text-ink-faint hover:text-rose-400 hover:border-rose-500/40">portals</button>
                    <button v-if="c.data.steps.length" @click="c.clearDirectives" title="Delete every directive — the portals stay" class="text-[10px] font-mono uppercase tracking-wide border border-line rounded px-1.5 py-0.5 text-ink-faint hover:text-rose-400 hover:border-rose-500/40">actions</button>
                </div>
            </div>
        </div>

        <!-- bulk add: one directive → every portal at once. Mirrors a directive row: action · detail · agent. -->
        <div v-if="building && groups.length > 1" class="mb-3 flex flex-wrap items-center gap-1.5 text-[10px] font-mono">
            <span class="uppercase tracking-wide text-ink-faint mr-0.5">To all</span>
            <select v-model="bulkAction" :class="[sel, 'text-[10px] uppercase text-accent/80']" title="action">
                <option v-for="a in ACTIONS" :key="a" :value="a">{{ a }}</option>
            </select>
            <select v-if="bulkAction === 'link'" v-model="bulkTarget" :class="[sel, 'flex-1 min-w-[7rem] text-[10px] uppercase text-accent/80']" title="link target portal">
                <option :value="null">— target —</option>
                <option v-for="w in placedWaypoints" :key="w.id" :value="w.id">{{ w.title || 'untitled' }}{{ anchorNo[w.id] ? ` (A${anchorNo[w.id]})` : '' }}</option>
            </select>
            <select v-else-if="bulkAction === 'mod'" v-model="bulkMods" :class="[sel, 'text-[10px] uppercase text-accent/80']" title="mod">
                <option value="">— mod —</option>
                <option v-for="m in MODS" :key="m" :value="m">{{ m }}</option>
            </select>
            <input v-else-if="bulkAction === 'farm keys'" type="number" min="1" max="999" v-model="bulkQty" placeholder="#" :class="[sel, 'w-14 text-[10px] text-accent/80 text-center']" title="keys per portal" />
            <select v-model="bulkAssignee" :class="[sel, 'text-[10px] uppercase text-accent/80']" title="assign to">
                <option :value="null">anyone</option>
                <option v-for="p in c.data.participants" :key="p.user_id" :value="p.user_id">{{ p.callsign }}</option>
            </select>
            <button @click="addToEveryPortal" :disabled="bulkAction === 'link' && !bulkTarget" class="uppercase tracking-wide border border-line rounded px-2 py-0.5 text-ink-dim hover:text-accent hover:border-accent disabled:opacity-40 disabled:cursor-not-allowed">Add</button>
        </div>

        <!-- ── location cards ── -->
        <draggable v-model="order" item-key="key" tag="div" handle=".plan-handle" :delay="HOLD_MS" :touchStartThreshold="8" :animation="160" ghost-class="mission-ghost" drag-class="mission-drag" :disabled="!canDrag" @end="onReorder">
            <template #item="{ element: g }">
            <div class="mission-slot mb-2">
            <div :id="'pcard-' + g.wp.id" class="mission-card border rounded-lg bg-surface overflow-hidden" :class="openId === g.wp.id ? 'border-accent' : 'border-line/60'">
                <div class="flex items-stretch bg-emerald-500/5">
                    <div class="plan-handle flex items-center gap-1.5 pl-1.5 pr-2 py-2 text-left select-none hover:bg-emerald-500/10 flex-1 min-w-0" role="button" tabindex="0" @click="toggle(g.wp.id)" @keydown.enter.prevent="toggle(g.wp.id)" @keydown.space.prevent="toggle(g.wp.id)" :title="canDrag ? 'press & hold to reorder' : ''">
                        <span v-if="lockedKeys.has(g.key)" class="w-4 flex justify-center shrink-0" title="locked until earlier waypoints are done"><Lock :size="11" class="text-ink-faint/70" /></span>
                        <span v-else class="text-[11px] font-mono text-ink-faint w-4 text-center shrink-0 tabular-nums">{{ g.seq }}</span>
                        <component :is="roleIcon(g.wp.role)" :size="14" class="shrink-0" :class="roleColor(g.wp.role)" :title="`${g.wp.role} portal`" />
                        <span class="text-xs min-w-0 flex-1 flex items-center gap-1.5" :class="g.allDone ? 'text-ink-faint line-through' : 'text-ink'">
                            <input v-if="editingId === g.wp.id" :id="'wpname-' + g.wp.id" v-model="editTitle" type="text" maxlength="160" placeholder="portal name"
                                @click.stop @mousedown.stop @keydown="onNameKeydown(g, $event)" @blur="saveRename(g)"
                                class="min-w-0 flex-1 bg-inset border border-accent/60 rounded px-1 py-0.5 text-xs text-ink focus:outline-none focus:border-accent" />
                            <template v-else>
                                <span class="truncate" :class="building && openId === g.wp.id ? 'cursor-text hover:text-accent' : ''" :title="building && openId === g.wp.id ? 'click to rename' : ''" @click="onNameClick(g, $event)">{{ g.wp.title || 'untitled' }}</span><span v-if="!g.placed" class="text-[10px] text-amber-400/80 shrink-0"> · unplaced</span>
                            </template>
                        </span>
                        <span v-if="distLabel(g)" class="shrink-0 inline-flex items-center gap-0.5 text-[10px] font-mono text-ink-faint" title="distance from your location (or the first portal)"><Navigation :size="10" /> {{ distLabel(g) }}</span>
                        <span v-if="g.mine" class="shrink-0 inline-flex items-center gap-0.5 text-[10px] font-mono text-accent border border-accent/40 rounded px-1 leading-tight" :title="`${g.mine} assigned to you`"><Star :size="10" /> {{ g.mine }}</span>
                        <span v-if="g.needed" class="shrink-0 text-[10px] font-mono inline-flex items-center gap-0.5" :class="g.short ? 'text-rose-400' : 'text-accent'" :title="`${g.held}/${g.needed} keys held`"><KeyRound :size="10" />{{ g.held }}/{{ g.needed }}</span>
                        <CheckCircle2 v-if="g.allDone" :size="15" class="shrink-0 text-accent" title="all directives complete" />
                        <span v-else-if="g.tasks.length" class="shrink-0 text-[10px] font-mono text-ink-faint">{{ g.done }}/{{ g.tasks.length }}</span>
                    </div>
                    <button v-if="building" @click.stop="c.removeWp(g.wp)" title="remove location" class="shrink-0 px-2 flex items-center text-ink-faint hover:text-rose-400 hover:bg-rose-500/10"><X :size="15" /></button>
                </div>

                <div v-if="openId === g.wp.id" class="border-t border-line">
                    <!-- keys & intel -->
                    <div class="px-2 py-2.5 border-b border-line/60">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="text-[10px] font-mono uppercase tracking-[0.14em] text-ink-faint">Keys &amp; intel</span>
                            <div class="flex items-center gap-3 shrink-0">
                                <span v-if="g.wp.catalog_status && CAT_BADGE[g.wp.catalog_status]" class="inline-flex items-center gap-1 text-[10px] font-mono uppercase tracking-wide" :class="CAT_BADGE[g.wp.catalog_status].cls"
                                    :title="g.wp.catalog_status === 'unverified' ? 'catalog name from a single source — not yet corroborated' : (g.wp.catalog_status === 'hidden' ? 'name disputed — pending owner review' : `catalog name · ${g.wp.catalog_sources || 0} source${g.wp.catalog_sources === 1 ? '' : 's'}`)">
                                    <component :is="CAT_BADGE[g.wp.catalog_status].icon" :size="12" />{{ CAT_BADGE[g.wp.catalog_status].label }}<span v-if="g.wp.catalog_sources"> · {{ g.wp.catalog_sources }}</span>
                                </span>
                                <button v-if="c.manage && g.wp.catalog_status && g.wp.catalog_status !== 'hidden'" @click="c.flagPortal(g.wp)" title="flag this catalog name as wrong" class="inline-flex items-center gap-1 text-[11px] font-mono text-ink-dim hover:text-rose-400"><Flag :size="13" /> flag</button>
                                <template v-if="g.placed">
                                    <a :href="g.nav" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-[11px] font-mono text-ink-dim hover:text-accent"><Navigation :size="13" /> nav</a>
                                    <a :href="g.street" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-[11px] font-mono text-ink-dim hover:text-accent" title="Google Street View — see the location"><Eye :size="13" /> street</a>
                                    <a :href="g.intel" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-[11px] font-mono text-ink-dim hover:text-accent"><Link2 :size="13" /> intel</a>
                                    <button @click="copyIntel(g)" class="inline-flex items-center gap-1 text-[11px] font-mono text-ink-dim hover:text-accent"><component :is="copied === g.wp.id ? Check : Copy" :size="13" /> {{ copied === g.wp.id ? 'copied' : 'copy' }}</button>
                                </template>
                                <button v-else-if="building && g.wp.lat == null" @click="attaching = attaching === g.wp.id ? null : g.wp.id" class="text-[10px] font-mono uppercase tracking-wide text-ink-dim hover:text-accent border border-line rounded px-1.5 py-0.5">set portal</button>
                            </div>
                        </div>
                        <div v-if="attaching === g.wp.id" class="relative mb-2">
                            <input v-model="attachQuery" @input="searchAttach" @keyup.esc="attaching = null" placeholder="search a portal to place this location…" class="w-full bg-inset border border-accent/40 rounded px-1.5 py-1.5 text-sm focus:outline-none" />
                            <ul v-if="attachResults.length" :class="menu"><li v-for="p in attachResults" :key="p.id"><button @click="doAttach(g.wp, p)" :class="menuItem"><MapPin :size="13" class="shrink-0" /><span class="truncate">{{ p.title }}</span><span v-if="p.status === 'unverified'" class="ml-auto shrink-0 text-[9px] font-mono uppercase tracking-wide text-amber-400/80" title="only 1 source has this name">unverified</span></button></li></ul>
                        </div>
                        <!-- keys settings + portal photo: on mobile the photo is a full-width banner on top; on desktop it sits at right -->
                        <div class="flex flex-col sm:flex-row items-start gap-3">
                            <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 items-baseline w-full sm:w-auto flex-1 min-w-0">
                                <span class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Type</span>
                                <div>
                                    <select v-if="building" :value="g.role" @change="(e) => c.setRole(g.wp, e)" class="bg-inset border border-line rounded px-1 py-0.5 text-xs font-mono uppercase text-ink-dim focus:border-accent focus:outline-none"><option>anchor</option><option>spine</option><option>target</option><option>waypoint</option></select>
                                    <span v-else class="text-xs font-mono uppercase text-ink-dim">{{ g.role }}</span>
                                </div>
                                <span v-if="g.placed" class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Keys</span>
                                <div v-if="g.placed" class="flex flex-wrap items-center gap-4">
                                    <span class="inline-flex items-center gap-1.5"><span class="text-[10px] font-mono uppercase text-ink-faint">need</span>
                                        <input v-if="building" type="number" min="0" :value="g.needed" @change="(e) => c.setKeyNeeded(g.wp, +e.target.value)" class="num-spin w-12 bg-inset border border-line rounded px-1 py-0.5 text-xs font-mono text-ink focus:border-accent focus:outline-none" />
                                        <span v-else class="text-xs font-mono text-ink">{{ g.needed }}</span>
                                    </span>
                                    <span class="inline-flex items-center gap-1.5"><span class="text-[10px] font-mono uppercase text-ink-faint">mine</span>
                                        <input type="number" min="0" :value="g.myKeys" @change="(e) => c.setKeyHeld(g.wp, +e.target.value)" class="num-spin w-12 bg-inset border border-line rounded px-1 py-0.5 text-xs font-mono text-accent focus:border-accent focus:outline-none" />
                                    </span>
                                </div>
                                <template v-if="g.est">
                                    <span class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Farm</span>
                                    <div class="text-[11px] font-mono text-ink-dim">
                                        <div class="text-ink">≈{{ rng(g.est.lo, g.est.hi) }} hacks</div>
                                        <div>{{ g.est.grind }}<span v-if="g.est.multiBurn" class="text-amber-300 inline-flex items-center gap-0.5"> · <TriangleAlert :size="10" /> {{ g.est.burnouts }} burnouts</span></div>
                                    </div>
                                </template>
                            </div>
                            <a v-if="g.wp.image" :href="g.wp.image" target="_blank" rel="noopener" class="order-first sm:order-none w-full sm:w-auto shrink-0" title="view full portal photo">
                                <img :src="g.wp.image" :alt="g.wp.title || 'portal'" loading="lazy" class="block mx-auto sm:mx-0 max-h-56 max-w-full object-contain sm:max-h-32 sm:max-w-40 rounded border border-line/60 bg-inset" />
                            </a>
                        </div>
                        <div v-if="g.holders.length" class="flex flex-wrap items-center gap-1 mt-2">
                            <span class="text-[10px] font-mono text-ink-faint shrink-0">held by</span>
                            <span v-for="h in g.holders" :key="h.user_id" class="text-[10px] font-mono border rounded px-1 leading-tight" :class="factionChip(h.faction)">{{ h.callsign }}:{{ h.qty }}</span>
                        </div>
                        <div class="mt-2.5"><WaypointIntel :wp="g.wp" :manage="building" :hide-empty="!building" @save="(f) => c.saveIntel(g.wp, f)" /></div>
                    </div>

                    <!-- actions (directives) -->
                    <div class="px-2 py-2.5">
                        <div class="text-[10px] font-mono uppercase tracking-[0.14em] text-ink-faint mb-2">Actions</div>

                        <!-- attach a real portal + templates (build) -->
                        <div v-if="building" class="mb-2 space-y-1.5">
                            <div v-if="c.data.templates.length || savingTpl !== g.wp.id" class="flex items-start gap-1.5">
                                <div class="flex flex-wrap items-center gap-1.5 flex-1 min-w-0">
                                    <button v-for="t in c.data.templates" :key="t.id" @click="c.applyTemplate(t.id, g.wp.id)" class="inline-flex items-center gap-1 text-[10px] font-mono uppercase text-accent/80 border border-line rounded px-1.5 py-0.5 hover:border-accent" :title="`apply « ${t.name} » (${t.count})`">{{ t.name }} <span class="text-ink-faint">·{{ t.count }}</span><span @click.stop="c.deleteTemplate(t.id)" class="text-ink-faint hover:text-rose-400" title="delete template">×</span></button>
                                </div>
                                <button v-if="savingTpl !== g.wp.id" @click="startSaveTpl(g.wp.id)" :disabled="!g.tasks.length" class="shrink-0 text-[10px] font-mono text-ink-dim hover:text-accent border border-dashed border-line rounded px-1.5 py-0.5 disabled:opacity-40" title="save these directives as a reusable template">+ template</button>
                            </div>
                            <div v-if="savingTpl === g.wp.id" class="flex items-center gap-2">
                                <input v-model="tplName" @keyup.enter="confirmSaveTpl(g)" @keyup.esc="savingTpl = null" placeholder="template name…" :class="[fld, 'flex-1 min-w-0']" />
                                <button @click="confirmSaveTpl(g)" :disabled="!tplName.trim()" class="shrink-0 bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-xs font-semibold rounded px-2 py-1 disabled:opacity-40">save</button>
                                <button @click="savingTpl = null" class="shrink-0 text-[11px] font-mono text-ink-faint hover:text-ink">cancel</button>
                            </div>
                        </div>

                        <!-- directive rows -->
                        <ul class="divide-y divide-line">
                            <li v-for="(s, i) in g.tasks" :key="s.id" :id="'pstep-' + s.id" class="py-1.5 transition-colors" :class="[{ 'opacity-55': s.done || lockedSteps.has(s.id) }, flashStep === s.id ? 'bg-accent/10 ring-1 ring-accent/60 rounded' : '']">
                                <div class="flex items-start gap-2">
                                    <div class="flex-1 min-w-0" :class="{ 'line-through decoration-ink-faint': s.done }">
                                        <div class="flex items-center gap-2">
                                            <input type="checkbox" :checked="s.done" @change="c.toggleStep(s)" :disabled="c.data.op.status === 'planning' || lockedSteps.has(s.id) || (s.assignee_id && s.assignee_id !== c.me && !c.manage)" :title="lockedSteps.has(s.id) ? 'Earlier directives first — this op runs in sequence' : (c.data.op.status === 'planning' ? 'Directives can be checked off once the op is active' : (s.assignee_id && s.assignee_id !== c.me && !c.manage ? `Only ${s.assignee} can complete this` : ''))" class="accent-emerald-500 shrink-0 disabled:opacity-40 disabled:cursor-not-allowed" />
                                            <select v-if="building" :value="s.action || 'note'" @change="(e) => c.setStepAction(s, e)" :class="[sel, 'w-[5rem] text-[10px] uppercase text-accent/80 shrink-0']"><option v-for="a in ACTIONS" :key="a" :value="a">{{ a }}</option></select>
                                            <span v-else class="text-[10px] font-mono uppercase text-accent/70 shrink-0">{{ s.action || 'note' }}</span>
                                            <select v-if="building && s.action === 'link'" :value="s.links?.[0] || ''" @change="(e) => c.setStepLinks(s, e.target.value ? [+e.target.value] : [])" :class="[sel, 'flex-1 min-w-0 text-[10px] uppercase text-accent/80']"><option value="">— target —</option><option v-for="w in linkTargets(g)" :key="w.id" :value="w.id">{{ w.n }}. {{ w.title || 'untitled' }}{{ anchorNo[w.id] ? ` (A${anchorNo[w.id]})` : '' }}</option></select>
                                            <select v-else-if="building && s.action === 'mod'" :value="s.mods || ''" @change="(e) => c.setStepMods(s, e.target.value)" :class="[sel, 'flex-1 min-w-0 text-[10px] uppercase text-accent/80']"><option value="">— mod —</option><option v-for="m in MODS" :key="m" :value="m">{{ m }}</option></select>
                                            <input v-else-if="building && s.action === 'farm keys'" type="number" min="1" max="999" :value="s.qty" @change="(e) => c.setStepQty(s, e.target.value)" placeholder="#" :class="[sel, 'w-16 self-stretch mr-auto text-[10px] text-accent/80 text-center']" />
                                            <span v-else class="flex-1 min-w-0 truncate text-[10px] font-mono uppercase text-accent/70">{{ secondDirective(s) }}</span>
                                            <select v-if="building" :value="s.assignee_id || ''" @change="(e) => c.assignStep(s, e)" :class="[sel, 'w-[5.4rem] text-[10px] uppercase text-accent/80 shrink-0']"><option value="">anyone</option><option v-for="p in c.data.participants" :key="p.user_id" :value="p.user_id">{{ p.callsign }}</option></select>
                                            <Link v-else-if="s.assignee" :href="`/ops/${c.data.op.id}?view=roster&agent=${s.assignee_id}`" class="text-[10px] font-mono uppercase shrink-0 hover:underline text-accent/70" :title="`${s.assignee} · roster`">@{{ s.assignee }}</Link>
                                            <span v-else class="text-[10px] font-mono uppercase shrink-0 text-ink-faint" title="open to any agent">anyone</span>
                                        </div>
                                        <input v-if="building" :value="s.text" spellcheck="true" @change="(e) => c.setStepText(s, e.target.value)" placeholder="+ description…" class="mt-1 w-full bg-transparent border-0 border-b border-transparent hover:border-line focus:border-accent text-sm text-ink focus:outline-none px-0.5" :class="{ 'line-through': s.done }" />
                                        <p v-else-if="s.text" class="mt-0.5 pl-7 text-sm text-ink">{{ s.text }}</p>
                                    </div>
                                    <div v-if="building" class="flex flex-col items-center gap-0.5 shrink-0 pt-0.5">
                                        <button @click="c.removeStep(s)" class="text-ink-faint hover:text-rose-400 text-xs leading-none" title="remove directive">×</button>
                                        <button @click="moveStep(s, g, -1)" :disabled="i === 0" class="text-ink-faint hover:text-accent disabled:opacity-20 leading-none" title="move up"><ChevronUp :size="13" /></button>
                                        <button @click="moveStep(s, g, 1)" :disabled="i === g.tasks.length - 1" class="text-ink-faint hover:text-accent disabled:opacity-20 leading-none" title="move down"><ChevronDown :size="13" /></button>
                                    </div>
                                </div>
                            </li>
                            <li v-if="!g.tasks.length" class="py-2 text-xs text-ink-faint text-center">No directives yet.</li>
                        </ul>

                        <!-- quick-add: four common objectives + more (build) -->
                        <div v-if="building" class="mt-2 flex flex-wrap items-center gap-1.5">
                            <span class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">+ directive</span>
                            <button v-for="a in COMMON" :key="a" @click="c.addTaskAt(g.wp.id, { action: a })" class="text-[10px] font-mono uppercase tracking-wide border border-line rounded px-1.5 py-0.5 text-ink-dim hover:text-accent hover:border-accent">{{ a }}</button>
                            <button @click="moreOpen[g.key] = !moreOpen[g.key]" class="text-[10px] font-mono uppercase tracking-wide text-ink-faint hover:text-accent px-1">{{ moreOpen[g.key] ? 'less' : 'more…' }}</button>
                            <template v-if="moreOpen[g.key]">
                                <button v-for="a in MORE" :key="a" @click="c.addTaskAt(g.wp.id, { action: a })" class="text-[10px] font-mono uppercase tracking-wide border border-line rounded px-1.5 py-0.5 text-ink-dim hover:text-accent hover:border-accent">{{ a }}</button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
            </div>
            </template>
        </draggable>
        <p v-if="!groups.length" class="text-center text-ink-faint text-xs py-4">{{ building ? 'Add a portal above to start the mission.' : 'No directives yet.' }}</p>
    </div>
</template>
