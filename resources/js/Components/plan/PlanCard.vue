<script setup>
// One portal in the Plan widget: a header that toggles it open (press & hold to drag), and a body of stacked
// sections — links + photo, portal facts, site intel, directives, templates. Operatives planning the op get
// edit controls; everyone else (and any active op) gets the read-only checklist.
import { inject, ref, computed, nextTick, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Navigation, Link2, Copy, Check, CheckCircle2, MapPin, X, Lock, Star, KeyRound, ChevronUp, ChevronDown, Trash2, ShieldCheck, ShieldQuestion, Flag, Eye } from 'lucide-vue-next';
import { roleIcon, roleColor } from '@/roles';
import { factionChip } from '@/faction';
import { COMMON, MORE, ACTIONS, hasDetail, catalogSearch } from '@/plan';
import { ui, up } from '@/ui';
import ActionDetail from '@/Components/plan/ActionDetail.vue';

const props = defineProps({
    g: { type: Object, required: true },          // the group from PlanWidget.buildGroup
    open: { type: Boolean, default: false },
    locked: { type: Boolean, default: false },    // sequential op: earlier portals first
    dist: { type: String, default: null },        // distance chip while sorting by distance
    canDrag: { type: Boolean, default: false },
    lockedSteps: { type: Set, required: true },
    flashStep: { type: Number, default: null },   // deep-linked directive to highlight
    targets: { type: Array, required: true },     // [{ id, label, placed }] every portal, for link targets
});
const emit = defineEmits(['toggle']);
const c = inject('opctx');
const building = computed(() => c.editable);
const wp = computed(() => props.g.wp);

const ROLES = ['anchor', 'spine', 'target', 'waypoint'];
// shared-catalog trust badge for a placed portal's name (from wp.catalog_status)
const CAT_BADGE = {
    verified: { label: 'verified', cls: 'text-accent', icon: ShieldCheck },
    owner_locked: { label: 'verified', cls: 'text-accent', icon: ShieldCheck },
    unverified: { label: 'unverified', cls: 'text-amber-400/90 daylight:text-amber-700', icon: ShieldQuestion },
    hidden: { label: 'disputed', cls: 'text-rose-400', icon: Flag },
};
const badge = computed(() => CAT_BADGE[wp.value.catalog_status] || null);
const badgeTitle = computed(() => ({
    unverified: 'catalog name from a single source — not yet corroborated',
    hidden: 'name disputed — pending owner review',
}[wp.value.catalog_status] || `catalog name · ${wp.value.catalog_sources || 0} source${wp.value.catalog_sources === 1 ? '' : 's'}`));

// op-local site intel — operatives edit any field; everyone else sees only the ones that are set
const INTEL = [
    { k: 'gate_pin', label: 'Gate PIN', ph: '—', show: 'font-mono font-medium text-accent' },
    { k: 'hours', label: 'Hours', ph: '—' },
    { k: 'parking', label: 'Parking', ph: '—', span: 'col-span-2 @md:col-span-1' },
    { k: 'access_notes', label: 'Access', ph: 'entry, stairs, lockbox…', span: 'col-span-full', area: true },
    { k: 'hazards', label: 'Hazards', ph: 'dogs, private property, traffic…', span: 'col-span-full', area: true, show: 'text-amber-400 daylight:text-amber-700' },
];
const intelShown = computed(() => (building.value ? INTEL : INTEL.filter((f) => wp.value[f.k])));
const ro = 'h-8 flex items-center text-xs font-mono uppercase text-ink'; // a read-only value, aligned with inputs

// ── header: inline rename (planning only, once the card is open) ─────────────────────────────────────
const editing = ref(false);
const editTitle = ref('');
const nameEl = ref(null);
watch(() => props.open, (o) => { if (!o) editing.value = false; });
function onNameClick(ev) {
    if (!building.value || !props.open) return; // let the click bubble up and toggle the card
    ev.stopPropagation();
    editTitle.value = wp.value.title || '';
    editing.value = true;
    nextTick(() => { nameEl.value?.focus(); nameEl.value?.select(); });
}
function onNameKeydown(ev) {
    ev.stopPropagation(); // keep Space/Enter from reaching the header's toggle
    if (ev.key === 'Enter') { ev.preventDefault(); saveRename(); }
    else if (ev.key === 'Escape') { ev.preventDefault(); editing.value = false; }
}
function saveRename() {
    if (!editing.value) return; // already closed (Esc, then blur)
    editing.value = false;
    const t = editTitle.value.trim();
    if (t !== (wp.value.title || '')) c.renameWaypoint(wp.value, t);
}

// ── links: navigate / street view / intel map / copy the intel link ──────────────────────────────────
const intelUrl = computed(() => `https://intel.ingress.com/?pll=${wp.value.lat},${wp.value.lng}`);
const links = computed(() => [
    { label: 'nav', icon: Navigation, href: `https://www.google.com/maps/dir/?api=1&destination=${wp.value.lat},${wp.value.lng}`, title: 'directions in Google Maps' },
    { label: 'street', icon: Eye, href: `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${wp.value.lat},${wp.value.lng}`, title: 'Google Street View — see the location' },
    { label: 'intel', icon: Link2, href: intelUrl.value, title: 'open on the Ingress Intel map' },
]);
const copied = ref(false);
async function copyIntel() {
    try {
        await navigator.clipboard.writeText(intelUrl.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch (e) { /* clipboard blocked */ }
}

// attach a real portal to an unplaced location
const attaching = ref(false);
const attachQuery = ref('');
const attachResults = ref([]);
const searchAttach = catalogSearch(attachQuery, attachResults);
function doAttach(p) { c.attachPortal(wp.value, p.id); attaching.value = false; attachQuery.value = ''; attachResults.value = []; }

// ── directives ─────────────────────────────────────────────────────────────────────────────────────
const linkTargets = computed(() => props.targets.filter((t) => t.id !== wp.value.id));
const add = (action) => { if (action) c.addTaskAt(wp.value.id, { action }); };
function saveDetail(s, p) {
    if ('links' in p) c.setStepLinks(s, p.links);
    else if ('mods' in p) c.setStepMods(s, p.mods);
    else c.setStepQty(s, p.qty);
}
function detailText(s) {
    if (s.action === 'link' && s.links?.length) return '→ ' + (c.data.waypoints.find((w) => w.id === s.links[0])?.title || 'portal');
    if (s.action === 'mod' && s.mods) return s.mods;
    if (s.action === 'farm keys' && s.qty) return '× ' + s.qty;
    return '';
}
// why a directive can't be checked off right now ('' = it can)
function checkBlock(s) {
    if (props.lockedSteps.has(s.id)) return 'Earlier directives first — this op runs in sequence';
    if (c.data.op.status === 'planning') return 'Directives can be checked off once the op is active';
    if (s.assignee_id && s.assignee_id !== c.me && !c.manage) return `Only ${s.assignee} can complete this`;
    return '';
}
// swap a directive with its neighbour; the server takes the whole op's step order (planning lists every portal)
function moveStep(i, dir) {
    const ids = props.g.tasks.map((t) => t.id);
    const j = i + dir;
    [ids[i], ids[j]] = [ids[j], ids[i]];
    c.reorderSteps(c.data.waypoints.flatMap((w) => (w.id === wp.value.id ? ids : c.data.steps.filter((s) => s.op_waypoint_id === w.id).map((s) => s.id))));
}

// ── templates: save this portal's directives as a reusable set ─────────────────────────────────────────
const savingTpl = ref(false);
const tplName = ref('');
const tplEl = ref(null);
function startSaveTpl() { tplName.value = ''; savingTpl.value = true; nextTick(() => tplEl.value?.focus()); }
function confirmSaveTpl() {
    const name = tplName.value.trim();
    if (!name || !props.g.tasks.length) return;
    c.saveTemplate(name, wp.value.id);
    savingTpl.value = false;
}
</script>

<template>
    <div :id="'pcard-' + wp.id" class="mission-card border rounded-lg bg-surface overflow-hidden" :class="open ? 'border-accent' : 'border-line/60'">
        <!-- header: tap to open · press & hold to reorder -->
        <div class="flex items-stretch bg-emerald-500/5">
            <div class="plan-handle flex-1 min-w-0 flex items-center gap-2 pl-2 pr-1 py-2.5 select-none hover:bg-emerald-500/10" role="button" tabindex="0"
                @click="emit('toggle')" @keydown.enter.prevent="emit('toggle')" @keydown.space.prevent="emit('toggle')" :title="canDrag ? 'press & hold to reorder' : ''">
                <span v-if="locked" class="w-4 flex justify-center shrink-0" title="locked until earlier portals are done"><Lock :size="12" class="text-ink-faint/70" /></span>
                <span v-else class="w-4 shrink-0 text-center text-[11px] font-mono tabular-nums text-ink-faint">{{ g.seq }}</span>
                <component :is="roleIcon(wp.role)" :size="15" class="shrink-0" :class="roleColor(wp.role)" />
                <input v-if="editing" ref="nameEl" v-model="editTitle" type="text" maxlength="160" placeholder="portal name"
                    @click.stop @mousedown.stop @keydown="onNameKeydown" @blur="saveRename" :class="ui.input" />
                <span v-else class="flex-1 min-w-0 truncate text-sm" :class="[g.allDone ? 'text-ink-faint line-through' : 'text-ink', building && open ? 'cursor-text hover:text-accent' : '']"
                    :title="building && open ? 'tap to rename' : ''" @click="onNameClick">{{ wp.title || 'untitled' }}<span v-if="!g.placed" class="text-[10px] text-amber-400/80 daylight:text-amber-700"> · unplaced</span></span>
                <span v-if="dist" class="shrink-0 inline-flex items-center gap-0.5 text-[10px] font-mono text-ink-faint" title="distance from your location (or the first portal)"><Navigation :size="10" />{{ dist }}</span>
                <span v-if="g.mine" class="shrink-0 inline-flex items-center gap-0.5 text-[10px] font-mono text-accent border border-accent/40 rounded px-1 leading-tight" :title="`${g.mine} assigned to you`"><Star :size="10" />{{ g.mine }}</span>
                <span v-if="g.needed" class="shrink-0 inline-flex items-center gap-0.5 text-[10px] font-mono" :class="g.short ? 'text-rose-400' : 'text-accent'" :title="`${g.held}/${g.needed} keys held`"><KeyRound :size="10" />{{ g.held }}/{{ g.needed }}</span>
                <CheckCircle2 v-if="g.allDone" :size="15" class="shrink-0 text-accent" title="all directives complete" />
                <span v-else-if="g.tasks.length" class="shrink-0 text-[10px] font-mono text-ink-faint" title="directives done">{{ g.done }}/{{ g.tasks.length }}</span>
            </div>
            <button v-if="building" @click.stop="c.removeWp(wp)" title="remove portal" class="w-10 shrink-0 flex items-center justify-center text-ink-faint hover:text-rose-400 hover:bg-rose-500/10"><X :size="16" /></button>
        </div>

        <div v-if="open" class="border-t border-line px-2.5 pt-3 pb-3.5 space-y-4">
            <!-- links + photo -->
            <div class="space-y-2">
                <div v-if="g.placed" class="grid grid-cols-4 gap-1.5">
                    <a v-for="l in links" :key="l.label" :href="l.href" target="_blank" rel="noopener" :title="l.title" :class="ui.btn"><component :is="l.icon" :size="14" class="shrink-0" />{{ l.label }}</a>
                    <button @click="copyIntel" title="copy the Intel link" :class="ui.btn"><component :is="copied ? Check : Copy" :size="14" class="shrink-0" />{{ copied ? 'copied' : 'copy' }}</button>
                </div>
                <template v-else-if="building">
                    <button v-if="!attaching" @click="attaching = true" :class="ui.btn" class="w-full"><MapPin :size="14" /> set portal</button>
                    <div v-else class="relative">
                        <input v-model="attachQuery" @input="searchAttach" @keyup.esc="attaching = false" placeholder="search a portal to place this location…" :class="ui.input" />
                        <ul v-if="attachResults.length" :class="ui.menu"><li v-for="p in attachResults" :key="p.id"><button @click="doAttach(p)" :class="ui.menuItem"><MapPin :size="13" class="shrink-0" /><span class="truncate">{{ p.title }}</span><span v-if="p.status === 'unverified'" class="ml-auto shrink-0 text-[9px] font-mono uppercase tracking-wide text-amber-400/80" title="only 1 source has this name">unverified</span></button></li></ul>
                    </div>
                </template>
                <div v-if="badge" class="flex items-center justify-between gap-2 text-[10px] font-mono uppercase tracking-wide">
                    <span class="inline-flex items-center gap-1" :class="badge.cls" :title="badgeTitle"><component :is="badge.icon" :size="12" />{{ badge.label }}<span v-if="wp.catalog_sources"> · {{ wp.catalog_sources }}</span></span>
                    <button v-if="c.manage && wp.catalog_status !== 'hidden'" @click="c.flagPortal(wp)" title="flag this catalog name as wrong" class="inline-flex items-center gap-1 text-ink-faint hover:text-rose-400"><Flag :size="12" /> flag name</button>
                </div>
                <a v-if="wp.image" :href="wp.image" target="_blank" rel="noopener" class="block rounded border border-line/60 bg-inset overflow-hidden" title="view the full portal photo">
                    <img :src="wp.image" :alt="wp.title || 'portal'" loading="lazy" class="block mx-auto max-h-48 max-w-full object-contain" />
                </a>
            </div>

            <!-- portal: type + key counts -->
            <section>
                <h4 :class="ui.sec">Portal</h4>
                <div class="grid grid-cols-[minmax(0,9rem)_5rem_5rem] gap-2">
                    <label>
                        <span :class="ui.label">Type</span>
                        <select v-if="building" :value="wp.role" @change="(e) => c.setRole(wp, e)" :class="ui.select"><option v-for="r in ROLES" :key="r" :value="r">{{ up(r) }}</option></select>
                        <span v-else :class="ro">{{ wp.role }}</span>
                    </label>
                    <template v-if="g.placed">
                        <label>
                            <span :class="ui.label">Keys need</span>
                            <input v-if="building" type="number" min="0" :value="g.needed" @change="(e) => c.setKeyNeeded(wp, +e.target.value)" :class="ui.num" />
                            <span v-else :class="ro">{{ g.needed }}</span>
                        </label>
                        <label>
                            <span :class="ui.label">Keys mine</span>
                            <input type="number" min="0" :value="g.myKeys" @change="(e) => c.setKeyHeld(wp, +e.target.value)" :class="ui.num" />
                        </label>
                    </template>
                </div>
                <div v-if="g.holders.length" class="mt-2">
                    <span :class="ui.label">Held by</span>
                    <div class="flex flex-wrap gap-1.5">
                        <span v-for="h in g.holders" :key="h.user_id" class="text-[11px] font-mono border rounded px-1.5 py-0.5" :class="factionChip(h.faction)">{{ h.callsign }} · {{ h.qty }}</span>
                    </div>
                </div>
            </section>

            <!-- site intel (op-local) -->
            <section v-if="intelShown.length">
                <h4 :class="ui.sec">Site intel<span v-if="building" class="normal-case tracking-normal text-ink-faint">· only this op sees it</span></h4>
                <div class="grid grid-cols-2 @md:grid-cols-3 gap-2">
                    <label v-for="f in intelShown" :key="f.k" :class="f.span">
                        <span :class="ui.label">{{ f.label }}</span>
                        <template v-if="building">
                            <textarea v-if="f.area" :value="wp[f.k]" @change="(e) => c.saveIntel(wp, { [f.k]: e.target.value })" rows="2" spellcheck="true" :placeholder="f.ph" :class="ui.area"></textarea>
                            <input v-else :value="wp[f.k]" @change="(e) => c.saveIntel(wp, { [f.k]: e.target.value })" :placeholder="f.ph" :class="ui.input" />
                        </template>
                        <p v-else class="text-sm whitespace-pre-line" :class="f.show || 'text-ink'">{{ wp[f.k] }}</p>
                    </label>
                </div>
            </section>

            <!-- directives -->
            <section>
                <h4 :class="ui.sec">Directives</h4>
                <ul class="space-y-1.5">
                    <li v-for="(s, i) in g.tasks" :key="s.id" :id="'pstep-' + s.id" class="rounded border transition-colors"
                        :class="[flashStep === s.id ? 'border-accent bg-accent/10' : 'border-line/60', { 'opacity-55': !building && (s.done || lockedSteps.has(s.id)) }]">
                        <!-- planning: action · assignee, its second field, a note; reorder / delete on the right -->
                        <div v-if="building" class="flex gap-1 p-1.5">
                            <div class="flex-1 min-w-0 space-y-1.5">
                                <div class="grid grid-cols-2 gap-1.5">
                                    <select :value="s.action || 'note'" @change="(e) => c.setStepAction(s, e)" :class="ui.select" title="action"><option v-for="a in ACTIONS" :key="a" :value="a">{{ up(a) }}</option></select>
                                    <select :value="s.assignee_id || ''" @change="(e) => c.assignStep(s, e)" :class="ui.select" title="assigned to"><option value="">ANYONE</option><option v-for="p in c.data.participants" :key="p.user_id" :value="p.user_id">{{ up(p.callsign) }}</option></select>
                                </div>
                                <ActionDetail v-if="hasDetail(s.action)" :action="s.action" :links="s.links" :mods="s.mods" :qty="s.qty" :targets="linkTargets" @update="(p) => saveDetail(s, p)" />
                                <input :value="s.text" @change="(e) => c.setStepText(s, e.target.value)" spellcheck="true" placeholder="note (optional)" :class="ui.input" />
                            </div>
                            <div class="flex flex-col">
                                <button @click="moveStep(i, -1)" :disabled="i === 0" :class="ui.icon" title="move up"><ChevronUp :size="16" /></button>
                                <button @click="moveStep(i, 1)" :disabled="i === g.tasks.length - 1" :class="ui.icon" title="move down"><ChevronDown :size="16" /></button>
                                <button @click="c.removeStep(s)" :class="ui.iconDanger" title="delete directive"><Trash2 :size="15" /></button>
                            </div>
                        </div>
                        <!-- running: check it off -->
                        <div v-else class="flex items-start gap-2.5 p-2">
                            <input type="checkbox" :checked="s.done" @change="c.toggleStep(s)" :disabled="!!checkBlock(s)" :title="checkBlock(s)" class="size-5 shrink-0 accent-emerald-500 disabled:opacity-40 disabled:cursor-not-allowed" />
                            <div class="flex-1 min-w-0" :class="{ 'line-through decoration-ink-faint': s.done }">
                                <div class="flex items-baseline gap-2 text-xs font-mono uppercase">
                                    <span class="shrink-0 text-accent">{{ s.action || 'note' }}</span>
                                    <span class="flex-1 min-w-0 truncate text-ink-dim">{{ detailText(s) }}</span>
                                    <Link v-if="s.assignee" :href="`/ops/${c.data.op.id}?view=roster&agent=${s.assignee_id}`" class="shrink-0 text-accent/80 hover:underline" :title="`${s.assignee} · roster`">@{{ s.assignee }}</Link>
                                    <span v-else class="shrink-0 text-ink-faint" title="open to any agent">anyone</span>
                                </div>
                                <p v-if="s.text" class="mt-1 text-sm text-ink">{{ s.text }}</p>
                            </div>
                        </div>
                    </li>
                </ul>
                <p v-if="!g.tasks.length" :class="ui.hint" class="py-1 text-center">No directives yet.</p>

                <div v-if="building" class="mt-3">
                    <span :class="ui.label">Add a directive</span>
                    <div class="grid grid-cols-3 @md:grid-cols-5 gap-1.5">
                        <button v-for="a in COMMON" :key="a" @click="add(a)" :class="ui.btn">{{ a }}</button>
                        <select @change="(e) => { add(e.target.value); e.target.value = ''; }" :class="ui.select" class="col-span-2 @md:col-span-1" title="more directive types">
                            <option value="">MORE…</option>
                            <option v-for="a in MORE" :key="a" :value="a">{{ up(a) }}</option>
                        </select>
                    </div>
                </div>
            </section>

            <!-- templates: reusable directive sets -->
            <section v-if="building">
                <h4 :class="ui.sec">Templates</h4>
                <div v-if="savingTpl" class="flex gap-1.5">
                    <input ref="tplEl" v-model="tplName" @keyup.enter="confirmSaveTpl" @keyup.esc="savingTpl = false" placeholder="name this template…" :class="ui.input" />
                    <button @click="confirmSaveTpl" :disabled="!tplName.trim()" :class="ui.primary">save</button>
                    <button @click="savingTpl = false" :class="ui.btn">cancel</button>
                </div>
                <template v-else>
                    <p :class="ui.hint" class="mb-2">{{ c.data.templates.length ? 'Tap a saved set to add its directives to this portal.' : 'Save this portal’s directives as a set to reuse on other portals.' }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        <div v-for="t in c.data.templates" :key="t.id" class="inline-flex">
                            <button @click="c.applyTemplate(t.id, wp.id)" :class="ui.btn" class="rounded-r-none" :title="`add « ${t.name} » (${t.count} directive${t.count === 1 ? '' : 's'})`">{{ t.name }}<span class="text-ink-faint">·{{ t.count }}</span></button>
                            <button @click="c.deleteTemplate(t.id)" :class="ui.danger" class="rounded-l-none border-l-0" title="delete template"><X :size="13" /></button>
                        </div>
                        <button @click="startSaveTpl" :disabled="!g.tasks.length" :class="ui.btn" class="border-dashed" title="save this portal’s directives as a reusable set">+ save as template</button>
                    </div>
                </template>
            </section>
        </div>
    </div>
</template>
