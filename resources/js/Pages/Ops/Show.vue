<script setup>
import { ref, reactive, computed, provide, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DashboardGrid from '@/Components/dashboard/DashboardGrid.vue';
import DashboardWidgetCard from '@/Components/dashboard/DashboardWidgetCard.vue';
import WidgetRenderer from '@/Components/dashboard/WidgetRenderer.vue';
import MissionComplete from '@/Components/MissionComplete.vue';
import QrCode from '@/Components/QrCode.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import EditOpModal from '@/Components/EditOpModal.vue';
import { useOpActions } from '@/opActions';
import { useLivePoll, usePresenceShare } from '@/useLive';
import { WIDGET_ICONS, OP_VIEWS } from '@/icons';
import { lsGet, lsSet, lsRemove } from '@/ls';
import { queryParam } from '@/url';
import { useCopied } from '@/useCopied';
import { useNotify } from '@/useNotify';
import { ui } from '@/ui';
import { useDeviceMode } from '@/useDeviceMode';
import { Share2, Pencil, Download, X, Satellite, LogOut, Undo2, PencilRuler, Radio, CircleCheckBig } from 'lucide-vue-next';

const props = defineProps({
    op: Object,
    participants: { type: Array, default: () => [] },
    banned: { type: Array, default: () => [] }, // operative-only: agents banned from this op (for the roster's unban action)
    waypoints: { type: Array, default: () => [] },
    steps: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] }, // the Tasks widget's checklist
    templates: { type: Array, default: () => [] },
    keyHoldings: { type: Array, default: () => [] },
    hackTimers: { type: Array, default: () => [] }, // the viewer's own hack timers on this op's portals
    presence: { type: Array, default: () => [] },
    sharing: { type: Boolean, default: false },
    layouts: { type: Object, default: () => ({ desktop: [], mobile: [] }) },
    widgetCatalog: { type: Object, default: () => ({}) },
    waiting: { type: Boolean, default: false },
    myNotes: { type: String, default: '' },
    aiConfig: { type: Object, default: null }, // the viewer's synced BYOK AI config (null unless cross-device sync is on)
});

const page = usePage();
const manage = computed(() => props.op.is_operative);
const editable = computed(() => manage.value && props.op.status === 'planning'); // plan edits locked once active
// op-status pill colours: planning (neutral) · active (green) · complete (sky)
const STATUS_PILL = { planning: 'border-line text-ink-dim', active: 'border-emerald-500/40 text-accent', complete: 'border-sky-400/40 text-sky-300' };
// the phone menu's status picker: an icon per status, coloured like the pill
const STATUS_ICON = { planning: PencilRuler, active: Radio, complete: CircleCheckBig };
const me = page.props.auth.user.id;

// Live: refetch op state every ~3s (NOT the layout — that stays local); opt-in GPS sharing.
useLivePoll(['op', 'participants', 'banned', 'waypoints', 'steps', 'tasks', 'keyHoldings', 'hackTimers', 'presence']);
const share = usePresenceShare(props.op.id, props.sharing);
const opts = { preserveScroll: true };
const base = computed(() => `/ops/${props.op.id}`);
const query = (name) => queryParam(page.url, name);

// ---- mission complete: pop the after-action report the moment the last directive is cleared ----
// all directives done — fires the after-action report. Includes 'complete' because finishing the last
// directive auto-flips the op to complete in the same response, and we still want the report to pop.
const allComplete = computed(() => (props.op.status === 'active' || props.op.status === 'complete') && props.steps.length > 0 && props.steps.every((s) => s.done));
const showComplete = ref(false);
watch(allComplete, (now, was) => { if (now && !was) showComplete.value = true; }); // only on the live transition, not on revisit

// a status flip swaps an agent between the standing-by screen and the live dashboard; a partial poll can't
// switch screens on its own, so toast the transition (the queue is a singleton → survives the reload) then
// refetch in full to land on the right screen. Operators stay put — they get their own flash on the flip.
const { notify } = useNotify();
watch(() => props.op.status, (status, prev) => {
    if (status === prev || manage.value) return;
    const msg = { active: `🟢 “${props.op.name}” is live — go!`, complete: `🏁 “${props.op.name}” is complete.`, planning: `“${props.op.name}” is back to planning.` }[status];
    if (msg) notify('', msg, status === 'active' ? 'success' : 'default');
    router.reload();
});
function openReport() { showComplete.value = true; }

// ---- share link ----
const joinUrl = computed(() => `${window.location.origin}/j/${props.op.join_token}`);
const { copied: linkCopied, copy } = useCopied();
const copyLink = () => copy(joinUrl.value);

// destructive actions ask first, through the in-app dialog (with its remembered "don't ask again")
const confirmDialog = ref(null);
const confirmAction = (message, action, key = null) => confirmDialog.value.ask(message, action, key);
const actions = useOpActions(props, confirmAction); // every plan / directive / task / roster request the widgets make

// ---- header edit ----
const editing = ref(false);
// a freshly-created op (?new=1 from the create redirect) opens straight into the edit modal
onMounted(() => {
    if (manage.value && query('new')) {
        editing.value = true;
        // drop ?new=1 so a reload doesn't reopen it — through Inertia's own page URL; a bare history.replaceState
        // got written back over by Inertia's next history save, so the param stuck
        const url = new URL(page.url, location.origin);
        url.searchParams.delete('new');
        router.replace({ url: url.pathname + url.search, preserveState: true, preserveScroll: true });
    }
});
function setStatus(s) { router.put(base.value, { status: s }, opts); }
const hasProgress = computed(() => props.steps.some((s) => s.done));
// once you've closed or left the op, the menu shouldn't offer it as "your last op" any more
const forgetLastOp = { onSuccess: () => { lsRemove('toady:lastOp'); lsRemove(`toady-ai-chat:${props.op.id}`); } }; // …nor keep its AI transcript
function closeOp() {
    const msg = hasProgress.value
        ? 'Close this op? Everything in it is permanently purged — save the after-action report first (Progress panel) if you want to keep the tally.'
        : 'Close this op? Everything in it is permanently purged.';
    confirmAction(msg,
        () => router.delete(base.value, forgetLastOp));
}
// any participant who isn't the owner can leave (the owner closes the op instead)
const isOwner = computed(() => props.op.owner_id === me);
function leaveOp() {
    confirmAction('Leave this op? Your shared location and key reports are removed — you can rejoin via the link.',
        () => router.delete(`${base.value}/leave`, forgetLastOp), 'leave');
}

// inline rename from the centered title (operative)
const renaming = ref(false);
const nameDraft = ref(props.op.name);
const nameInput = ref(null);
function startRename() { if (!editable.value) return; nameDraft.value = props.op.name; renaming.value = true; nextTick(() => nameInput.value?.focus()); }
function saveRename() { if (!renaming.value) return; /* Enter also blurs the box → would save twice */ renaming.value = false; const n = nameDraft.value.trim(); if (n && n !== props.op.name) router.put(base.value, { name: n }, opts); }
// a participant's private per-op notepad — fire-and-forget axios (no page refetch). `myNotes` is the one live copy
// every widget reads (Notes edits it, the AI's "share to my notes" appends to it), so neither overwrites the other.
const myNotes = ref(props.myNotes || '');
function saveNotes(text, scope = 'mine') {
    if (scope === 'mine') myNotes.value = text || '';
    window.axios.put(`${base.value}/notes`, { notes: text, scope }).catch(() => {});
}

// Shared selection: picking a waypoint/user (on the map or in a widget) flies the map in and
// flips that widget to a detail view; clicking the map background clears it.
const selection = ref(null); // { type:'waypoint'|'user', id }
function select(type, id) { selection.value = { type, id }; }
function clearSelection() { selection.value = null; }

// Per-agent route preview: the Roster's "show route" button asks the map to draw just that
// agent's predicted path; clicking the same agent again clears it. null = no route on the map.
// Remembered per-op across refreshes (same scheme as the map's other sticky settings).
const routeAgentKey = `toady-route-agent:${props.op.id}`;
const routeAgent = ref(Number(lsGet(routeAgentKey)) || null);
function showRoute(userId) { routeAgent.value = routeAgent.value === userId ? null : userId; }
watch(routeAgent, (id) => lsSet(routeAgentKey, id ? String(id) : ''));

// Everything the widgets need, injected so each widget stays a thin presentational shell.
provide('opctx', {
    // getters so c.manage / c.editable unwrap to reactive booleans in widget templates
    // (a plain injected object does NOT auto-unwrap nested refs — c.manage would be truthy always)
    ...actions, // first: its canFan ref is replaced by the unwrapping getter below
    data: props,
    get manage() { return manage.value; },
    get editable() { return editable.value; },
    get canFan() { return actions.canFan.value; }, // enable-gate for the auto button (fan geometry present)
    get focus() { return focus.value; }, // the full-page view key, or null on the dashboard
    get myNotes() { return myNotes.value; }, // the viewer's private notes, live
    me, share, saveNotes,
    selection, select, clearSelection,
    routeAgent, showRoute,
    openReport,
});

// ---- customizable widget grid (per-user; desktop + mobile) ----
const grid = reactive({
    desktop: props.layouts.desktop.map((i) => ({ ...i })),
    mobile: props.layouts.mobile.map((i) => ({ ...i })),
});
const invitePop = ref(false);   // invite-link popover in the top bar
// The op's tools, defined once: top-bar icons on desktop; on phones they sit in the main menu (#op-menu) — icons in
// the compact menu, labeled tiles in the labeled one.
const opTools = computed(() => {
    const { status, undo_count: undoCount } = props.op;
    return [
        manage.value && { id: 'undo', icon: Undo2, run: actions.undo, disabled: status !== 'planning' || !undoCount,
            label: status === 'planning' && undoCount ? `Undo · ${undoCount}` : 'Undo',
            title: status !== 'planning' ? 'Undo is only available while planning'
                : !undoCount ? 'Nothing to undo'
                : `Undo - ${props.op.undo_label || 'the last plan change'}` + (undoCount > 1 ? ` · ${undoCount - 1} more after this` : '') },
        manage.value && { id: 'invite', icon: Share2, run: () => { invitePop.value = true; }, label: 'Invite', title: 'invite agents (link + QR)' },
        manage.value && { id: 'edit', icon: Pencil, run: () => { editing.value = true; }, label: 'Edit op', title: 'edit op' },
        (manage.value || props.op.allow_export) && { id: 'export', icon: Download, href: `/ops/${props.op.id}/export`, label: 'Export', title: 'export plan' },
        manage.value && { id: 'close', icon: X, run: closeOp, danger: true, label: 'Close op', title: 'close + purge op' },
        !isOwner.value && { id: 'leave', icon: LogOut, run: leaveOp, danger: true, label: 'Leave op', title: 'leave op' },
    ].filter(Boolean);
});
const { mode: deviceMode } = useDeviceMode(); // 'mobile' | 'desktop' — follows the viewport
const layoutNonce = ref(0);        // bump to force the grid to adopt a collapse without remounting
const activeLayout = computed(() => grid[deviceMode.value]);

// ---- the dashboard grid vs. full pages ----
// Every widget's full page (?view=…) is always available, whether or not the widget is on the dashboard;
// the grid only decides what the dashboard shows.
// Save a grid to the server as a quiet background request (plain axios, NOT an Inertia visit) so it never
// triggers the progress bar alongside a navigation.
function saveLayout(mode) {
    window.axios.put('/dashboard/layout', { op_id: props.op.id, mode, layout: grid[mode] }).catch(() => {});
}
const nextY = (items) => items.reduce((a, i) => Math.max(a, i.y + i.h), 0);
// Navigate right away (never gated on the background save — a slow save once bounced users back), with
// preserveState so Inertia doesn't remount the page.
const go = (url) => router.visit(url, { preserveState: true, preserveScroll: true });
function openPage(key) { go(`${base.value}?view=${key}`); } // the widget stays on the dashboard
function closeWidget(key) {         // take a widget off the current device's dashboard (its page stays available)
    const mode = deviceMode.value;
    grid[mode] = grid[mode].filter((i) => i.i !== key);
    saveLayout(mode);
}
function addWidget(key) {           // full page → onto the current device's dashboard, then back to it
    const mode = deviceMode.value;
    if (!grid[mode].some((i) => i.i === key)) {
        const cat = props.widgetCatalog[key] || {};
        grid[mode] = [...grid[mode], { i: key, x: 0, y: nextY(grid[mode]), w: mode === 'mobile' ? 12 : (cat.w || 6), h: cat.h || 5 }];
    }
    saveLayout(mode);
    go(base.value);
}
function collapseWidget(key) {     // collapse/expand a widget down to just its header
    const mode = deviceMode.value;
    grid[mode] = grid[mode].map((i) => i.i !== key ? i
        : (i.collapsed ? { ...i, collapsed: false, h: i.fullH || i.h } : { ...i, collapsed: true, fullH: i.h, h: 1 }));
    layoutNonce.value++;
    persist();
}

let saveTimer;
function persist() {
    const mode = deviceMode.value;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => saveLayout(mode), 600);
}
function onLayoutUpdate(next) { grid[deviceMode.value] = next; persist(); }

onBeforeUnmount(() => clearTimeout(saveTimer));

// Full pages (menu → ?view=…): any widget reused full-screen — except the pinned Map + Plan, which only live
// on the dashboard. A ?view= link to one of those (Advisories, the keys-ready alert) scrolls to it instead.
const focusViews = Object.fromEntries(OP_VIEWS.filter((v) => !v.pinned).map((v) => [v.key, v.label]));
const pinnedViews = new Set(OP_VIEWS.filter((v) => v.pinned).map((v) => v.key));
function scrollToPinned() {
    const key = query('view');
    if (pinnedViews.has(key)) nextTick(() => setTimeout(() => document.getElementById('w-' + key)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 140));
}
onMounted(scrollToPinned);
watch(() => page.url, scrollToPinned);
const focusScroll = OP_VIEWS.filter((v) => v.scroll).map((v) => v.key); // list views scroll; the rest manage their own height
const opOnlyViews = new Set(OP_VIEWS.filter((v) => v.op).map((v) => v.key)); // operator-only full pages (activity log)
// ?view=… always opens that full page (menu links, alerts, in-widget links alike)
const focus = computed(() => {
    const v = query('view');
    return focusViews[v] && (!opOnlyViews.has(v) || manage.value) ? v : null;
});
const onGrid = (key) => activeLayout.value.some((i) => i.i === key);
</script>

<template>
    <Head :title="op.name" />
    <AppLayout>
        <!-- op title, centered in the top bar -->
        <template #title>
            <div class="flex items-center gap-2 min-w-0">
                <input v-if="renaming" ref="nameInput" v-model="nameDraft" @blur="saveRename" @keyup.enter="saveRename" @keyup.esc="renaming = false"
                    maxlength="120" class="bg-inset border border-accent/40 rounded px-1.5 py-0.5 text-sm font-mono text-ink min-w-0 max-w-[16rem] focus:outline-none" />
                <button v-else-if="editable" @click="startRename" class="font-mono text-accent glow tracking-wide truncate hover:opacity-80" title="rename op">{{ op.name }}</button>
                <span v-else class="font-mono text-accent glow tracking-wide truncate">{{ op.name }}</span>
                <select v-if="manage" :value="op.status" @change="(e) => setStatus(e.target.value)" title="op status — planning → active → complete"
                    class="hidden sm:inline-block shrink-0 text-[10px] font-mono uppercase border rounded px-1 py-0.5 bg-inset focus:outline-none focus:border-accent" :class="STATUS_PILL[op.status]">
                    <option value="planning">planning</option>
                    <option value="active">active</option>
                    <option value="complete">complete</option>
                </select>
                <span v-else class="hidden sm:inline-block shrink-0 text-[10px] font-mono uppercase border rounded px-1.5 py-0.5" :class="STATUS_PILL[op.status]">● {{ op.status }}</span>
            </div>
        </template>

        <!-- op tools (right) — desktop top bar; on phones they move into the main menu (#op-menu) -->
        <template #actions>
            <div class="hidden sm:flex items-center gap-0.5">
                <component :is="t.href ? 'a' : 'button'" v-for="t in opTools" :key="t.id" :href="t.href" @click="t.run?.()" :disabled="t.disabled" :title="t.title"
                    :class="t.danger ? ui.barIconDanger : ui.barIcon"><component :is="t.icon" :size="17" /></component>
            </div>
        </template>

        <!-- phones: the op tools inside the main menu — icons in the compact menu, labeled tiles in the labeled one — and a status picker -->
        <template #op-menu="{ close, compact }">
            <div :class="compact ? 'flex items-center gap-0.5' : 'grid grid-cols-2 gap-1'">
                <component :is="t.href ? 'a' : 'button'" v-for="t in opTools" :key="t.id" :href="t.href" @click="close(); t.run?.()" :disabled="t.disabled" :title="t.title"
                    :class="compact ? (t.danger ? ui.barIconDanger : ui.barIcon) : (t.danger ? ui.menuTileDanger : ui.menuTile)">
                    <component :is="t.icon" :size="compact ? 17 : 16" class="shrink-0" /><span v-if="!compact" class="truncate">{{ t.label }}</span>
                </component>
            </div>
            <label class="mt-1 mb-1.5 flex items-center gap-2 px-2">
                <component :is="STATUS_ICON[op.status]" :size="16" class="shrink-0" :class="STATUS_PILL[op.status]" />
                <span class="text-xs text-ink-dim">Status</span>
                <select v-if="manage" :value="op.status" @change="(e) => { close(); setStatus(e.target.value); }" :class="ui.select" class="ml-auto w-32!">
                    <option value="planning">PLANNING</option><option value="active">ACTIVE</option><option value="complete">COMPLETE</option>
                </select>
                <span v-else class="ml-auto text-[11px] font-mono uppercase border rounded px-2 py-1" :class="STATUS_PILL[op.status]">{{ op.status }}</span>
            </label>
        </template>

        <!-- agent standing by: planning op is hidden until it goes active -->
        <div v-if="waiting" class="flex flex-col items-center justify-center text-center py-24 gap-3">
            <Satellite :size="44" class="text-accent/70" :stroke-width="1.5" />
            <h2 class="font-mono text-accent glow text-lg tracking-wide">Standing by</h2>
            <p class="text-ink-dim text-sm max-w-sm leading-relaxed">
                <span class="text-ink">{{ op.name }}</span> is still being set up by Operator {{ op.owner }}.
                You'll get the go the moment it's live.
            </p>
            <span class="text-[10px] font-mono uppercase border border-line rounded px-1.5 py-0.5 text-ink-faint">● {{ op.status }}</span>
        </div>

        <!-- a widget's full page — its header adds it to the dashboard (when it isn't there) or goes back -->
        <div v-else-if="focus" class="h-[calc(100dvh-5.5rem)]">
            <DashboardWidgetCard :title="focusViews[focus]" :icon="WIDGET_ICONS[focus]" :scroll="focusScroll.includes(focus)"
                mode="page" :on-dashboard="onGrid(focus)" @add="addWidget(focus)" @back="go(base)">
                <WidgetRenderer :widget="focus" />
            </DashboardWidgetCard>
        </div>

        <DashboardGrid v-else :key="deviceMode" class="-mt-3" :layout="activeLayout" :sync-key="layoutNonce" :catalog="widgetCatalog"
            :mobile="deviceMode === 'mobile'"
            @update:layout="onLayoutUpdate" @open-page="openPage" @close="closeWidget" @toggle-collapse="collapseWidget" />

        <EditOpModal v-if="editing" :op="op" :editable="editable" @close="editing = false" @close-op="closeOp" />

        <!-- invite: link + scannable QR (works on desktop header + mobile menu; show a phone to scan-to-join) -->
        <div v-if="invitePop && op.join_token" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70" @click.self="invitePop = false">
            <div class="w-full max-w-xs bg-surface border border-line rounded-lg shadow-2xl p-4">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Invite agents</span>
                    <button @click="invitePop = false" class="text-ink-faint hover:text-ink"><X :size="16" /></button>
                </div>
                <div class="flex flex-col items-center gap-2">
                    <QrCode :value="joinUrl" :size="184" />
                    <p class="text-[10px] font-mono text-ink-faint">Scan to join the op</p>
                </div>
                <div class="flex gap-2 mt-3">
                    <input :value="joinUrl" readonly @focus="$event.target.select()" class="flex-1 min-w-0 bg-inset border border-line rounded px-1.5 py-1.5 text-xs font-mono text-accent" />
                    <button @click="copyLink" class="bg-emerald-500/20 hover:bg-emerald-500/30 text-accent font-mono text-xs rounded px-2 shrink-0">{{ linkCopied ? 'copied' : 'copy' }}</button>
                </div>
            </div>
        </div>

        <!-- after-action report: auto-pops at 100%, re-openable from the progress widget -->
        <MissionComplete v-if="showComplete" @close="showComplete = false" />

        <ConfirmDialog ref="confirmDialog" />
    </AppLayout>
</template>
