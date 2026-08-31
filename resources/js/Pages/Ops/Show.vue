<script setup>
import { ref, reactive, computed, provide, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DashboardGrid from '@/Components/dashboard/DashboardGrid.vue';
import DashboardWidgetCard from '@/Components/dashboard/DashboardWidgetCard.vue';
import WidgetRenderer from '@/Components/dashboard/WidgetRenderer.vue';
import MissionComplete from '@/Components/MissionComplete.vue';
import QrCode from '@/Components/QrCode.vue';
import { useLivePoll, usePresenceShare } from '@/useLive';
import { WIDGET_ICONS, OP_VIEWS } from '@/icons';
import { lsGet, lsSet } from '@/ls';
import { useNotify } from '@/useNotify';
import { Share2, Pencil, Download, X, Satellite, Minimize2, LogOut, Undo2, PencilRuler, Radio, CircleCheckBig } from 'lucide-vue-next';

const props = defineProps({
    op: Object,
    participants: { type: Array, default: () => [] },
    banned: { type: Array, default: () => [] }, // operative-only: agents banned from this op (for the roster's unban action)
    waypoints: { type: Array, default: () => [] },
    steps: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    keyHoldings: { type: Array, default: () => [] },
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
// mobile status toggle: an icon per status + the colour of the selected one (planning neutral · active green · complete sky)
const STATUSES = ['planning', 'active', 'complete'];
const STATUS_ICON = { planning: PencilRuler, active: Radio, complete: CircleCheckBig };
const STATUS_BTN = { planning: 'border-line text-ink-dim bg-line/30', active: 'border-emerald-500/40 text-accent bg-emerald-500/15', complete: 'border-sky-400/40 text-sky-300 bg-sky-400/15' };
const me = page.props.auth.user.id;

// Live: refetch op state every ~3s (NOT the layout — that stays local); opt-in GPS sharing.
useLivePoll(['op', 'participants', 'banned', 'waypoints', 'steps', 'keyHoldings', 'presence']);
const share = usePresenceShare(props.op.id, props.sharing);
const opts = { preserveScroll: true };
const barIcon = 'flex items-center justify-center w-9 h-8 rounded text-ink-dim hover:bg-emerald-500/10 hover:text-accent text-base';
const barIconDanger = 'flex items-center justify-center w-9 h-8 rounded text-ink-faint hover:bg-rose-500/10 hover:text-rose-400';
const base = computed(() => `/ops/${props.op.id}`);

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
const linkCopied = ref(false);
function copyLink() {
    navigator.clipboard?.writeText(joinUrl.value);
    linkCopied.value = true;
    setTimeout(() => { linkCopied.value = false; }, 1600);
}

// In-app confirm with a reliable "don't ask again" — Chrome only offers the native checkbox sporadically.
// confirmAction(message, action, key?): if the key's skip-pref is set it runs immediately, else opens the modal.
const confirmModal = reactive({ open: false, message: '', key: null, remember: false, action: null });
function confirmAction(message, action, key = null) {
    let skip = false;
    try { skip = key && localStorage.getItem('toady:skip:' + key) === '1'; } catch (e) { /* */ }
    if (skip) { action(); return; }
    Object.assign(confirmModal, { open: true, message, key, remember: false, action });
}
function confirmYes() {
    if (confirmModal.remember && confirmModal.key) { try { localStorage.setItem('toady:skip:' + confirmModal.key, '1'); } catch (e) { /* */ } }
    confirmModal.open = false;
    const a = confirmModal.action; confirmModal.action = null; a?.();
}
function confirmNo() { confirmModal.open = false; confirmModal.action = null; }

// ---- header edit ----
const editing = ref(false);
const header = reactive({ name: props.op.name, description: props.op.description || '', type: props.op.type, goals: props.op.goals || '', allow_export: props.op.allow_export });
function saveHeader() { router.put(base.value, header, { ...opts, onSuccess: () => (editing.value = false) }); }
// a freshly-created op (?new=1 from the create redirect) opens straight into the edit modal
onMounted(() => {
    if (manage.value && new URLSearchParams(page.url.split('?')[1] || '').get('new')) {
        editing.value = true;
        window.history.replaceState({}, '', page.url.split('?')[0]); // drop ?new=1 so a reload doesn't reopen it
    }
});
function setStatus(s) { router.put(base.value, { status: s }, opts); }
// mobile status control: one tap advances planning → active → complete → planning
function cycleStatus() { setStatus(STATUSES[(STATUSES.indexOf(props.op.status) + 1) % STATUSES.length]); }
const hasProgress = computed(() => props.steps.some((s) => s.done));
function closeOp() {
    const msg = hasProgress.value
        ? 'Close this op? Everything in it is permanently purged — save the after-action report first (Progress panel) if you want to keep the tally.'
        : 'Close this op? Everything in it is permanently purged.';
    confirmAction(msg,
        () => router.delete(base.value, { onSuccess: () => { try { localStorage.removeItem('toady:lastOp'); } catch (e) { /* */ } } }));
}
// any participant who isn't the owner can leave (the owner closes the op instead)
const isOwner = computed(() => props.op.owner_id === me);
function leaveOp() {
    confirmAction('Leave this op? Your shared location and key reports are removed — you can rejoin via the link.',
        () => router.delete(`${base.value}/leave`, { onSuccess: () => { try { localStorage.removeItem('toady:lastOp'); } catch (e) { /* */ } } }), 'leave');
}

// inline rename from the centered title (operative)
const renaming = ref(false);
const nameDraft = ref(props.op.name);
const nameInput = ref(null);
function startRename() { if (!editable.value) return; nameDraft.value = props.op.name; renaming.value = true; nextTick(() => nameInput.value?.focus()); }
function saveRename() { renaming.value = false; const n = nameDraft.value.trim(); if (n && n !== props.op.name) router.put(base.value, { name: n }, opts); }
// a participant's private per-op notepad — fire-and-forget axios (no page refetch); the textarea holds the live value
function saveNotes(text, scope = 'mine') { window.axios.put(`${base.value}/notes`, { notes: text, scope }).catch(() => {}); }

// ---- locations (waypoints) ----
const dropMode = ref(false);
function onMapDrop(c) {
    if (!dropMode.value) return;
    const title = prompt('Portal name — leave blank to auto-name from the map:') ?? '';
    router.post(`${base.value}/waypoints`, { lat: c.lat, lng: c.lng, title }, opts);
}
function addFromCatalog(p) { router.post(`${base.value}/waypoints`, { portal_id: p.id }, opts); }
// add a waypoint from a typed name OR a pasted Intel/Maps/scanner link (→ coords, + portal GUID if present)
function addLocation(text) {
    const s = (text || '').trim();
    if (!s) return;
    // scanner Share links (link.ingress.com) arrive URL-encoded (pll%3D…, %2Fportal%2F…); decode so both regexes match
    let d = s; try { d = decodeURIComponent(s); } catch (e) { /* leave as-is */ }
    const g = d.match(/portal\/([0-9a-f]{32}\.[0-9a-f]+)/i);       // authoritative Niantic portal id, when the link carries one
    const m = d.match(/pll=(-?\d+\.\d+),(-?\d+\.\d+)/) || d.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/) || d.match(/(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/);
    if (m) router.post(`${base.value}/waypoints`, { lat: parseFloat(m[1]), lng: parseFloat(m[2]), guid: g ? g[1] : undefined }, opts);
    else router.post(`${base.value}/waypoints`, { title: s }, opts);
}
function attachPortal(wp, portalId) { router.put(`${base.value}/waypoints/${wp.id}`, { portal_id: portalId }, opts); }
function renameWaypoint(wp, title) { router.put(`${base.value}/waypoints/${wp.id}`, { title }, opts); }
// dispute a shared-catalog portal name as wrong (enough flags hide it, pending owner review)
function flagPortal(wp) { confirmAction(`Flag "${wp.title}" as the wrong portal name? An owner will review it.`, () => router.post(`${base.value}/waypoints/${wp.id}/flag`, {}, opts), 'ban'); }
// operatives add op-local portal intel (purged with the op) — allowed any time, not just planning
function saveIntel(wp, fields) { router.put(`${base.value}/waypoints/${wp.id}/intel`, fields, opts); }
function reorderWaypoints(ids) { router.post(`${base.value}/waypoints/reorder`, { order: ids }, opts); }
function reorderSteps(ids) { router.post(`${base.value}/steps/reorder`, { order: ids }, opts); }
function clearDirectives() {
    confirmAction('Clear every directive from all locations? The locations stay — only their directives are deleted. You can undo this.',
        () => router.post(`${base.value}/steps/clear`, {}, opts));
}
function clearWaypoints() {
    confirmAction('Delete EVERY portal and its directives? This wipes the whole plan. You can undo this.',
        () => router.post(`${base.value}/waypoints/clear`, {}, opts));
}
// objective templates — save a location's directives, reuse them on any location in any of your ops
function saveTemplate(name, wpId) { router.post(`${base.value}/step-templates`, { name, op_waypoint_id: wpId }, opts); }
function applyTemplate(tplId, wpId) { router.post(`${base.value}/step-templates/${tplId}/apply`, { op_waypoint_id: wpId }, opts); }
function deleteTemplate(tplId) { router.delete(`${base.value}/step-templates/${tplId}`, opts); }
// key locker
function setKeyHeld(w, qty) { router.put(`${base.value}/keys/${w.id}`, { qty: Math.max(0, qty | 0) }, opts); }
function setKeyNeeded(w, qty) { router.put(`${base.value}/keys/${w.id}/needed`, { keys_needed: Math.max(0, qty | 0) }, opts); }
// auto-fan: one action — from the anchors, generate the fan link directives and/or per-location farm-keys
// (mode = 'links' | 'keys' | 'both'), always (re)setting key targets. Replaces the kinds it generates.
function autoFan(mode, assigneeId) {
    const what = mode === 'links' ? 'the fan link directives' : mode === 'keys' ? 'a farm-keys directive per location (qty = keys needed)' : 'the fan link directives and a farm-keys directive per location';
    confirmAction(`Auto builds the fan — it sets every key target and replaces ${mode === 'keys' ? 'ALL farm-key directives' : mode === 'links' ? 'ALL link directives' : 'ALL link + farm-key directives'} with ${what}. Manual links/key counts will be overwritten. Continue?`,
        () => router.post(`${base.value}/plan/fan`, { mode, assignee_id: assigneeId || null }, opts), 'autoFan');
}
// fan-geometry gate for auto-fan (Directives panel): either 2 placed anchors + at
// least 1 other placed portal (double-anchor fan), or 1 placed anchor + at least 2 others (single-anchor
// fan) — enough coords to promote spines and build a fan with at least one field.
const canFan = computed(() => {
    let placedAnchors = 0, otherPlaced = 0;
    for (const w of props.waypoints) {
        if (w.lat == null) continue;
        if (w.role === 'anchor') placedAnchors++; else otherPlaced++;
    }
    return (placedAnchors === 2 && otherPlaced >= 1) || (placedAnchors === 1 && otherPlaced >= 2);
});
// import an IITC field plan (Draw Tools / Bookmarks export) → waypoints + link directives + key needs
function importPlan(text, onDone, portalsOnly = false) { router.post(`${base.value}/plan/import`, { plan: text, portals_only: portalsOnly }, { ...opts, onSuccess: onDone }); }
// pop the plan undo stack (server restores the last snapshot); no confirm — undo IS the recovery action
function undo() { if (props.op.undo_count) router.post(`${base.value}/undo`, {}, opts); }
function setRole(w, e) { router.put(`${base.value}/waypoints/${w.id}`, { role: e.target.value }, opts); }
function removeWp(w) { confirmAction(`Remove “${w.title || 'untitled'}” and its directives?`, () => router.delete(`${base.value}/waypoints/${w.id}`, opts), 'removeWp'); }

// ---- directives (steps) ----
// add a directive under a location; draft = reactive { text, action, assignee_id, withComment } owned by the widget
function addTaskAt(wpId, draft) {
    const t = (draft.text || '').trim();
    if (!t && !draft.action) return; // need an objective and/or a comment
    const payload = { text: t || null, action: draft.action || null, op_waypoint_id: wpId || null, assignee_id: draft.assignee_id || null };
    if (draft.action === 'link' && draft.links?.length) payload.links = draft.links; // the 2nd directive — shown on its own
    if (draft.action === 'mod' && draft.mods) payload.mods = draft.mods;
    if (draft.action === 'farm keys' && draft.qty) payload.qty = +draft.qty;
    router.post(`${base.value}/steps`, payload, { ...opts, onSuccess: () => { draft.text = ''; draft.withComment = false; draft.links = []; draft.mods = ''; draft.qty = null; } });
}
// the same quick-add, applied to every portal at once ("to all") — one atomic request, one undo entry
function addTaskToAll(draft) {
    if (!draft.action) return;
    const payload = { action: draft.action, assignee_id: draft.assignee_id || null };
    if (draft.action === 'link' && draft.links?.length) payload.links = draft.links;
    if (draft.action === 'mod' && draft.mods) payload.mods = draft.mods;
    if (draft.action === 'farm keys' && draft.qty) payload.qty = +draft.qty;
    router.post(`${base.value}/steps/bulk`, payload, opts);
}
function toggleStep(s) { router.put(`${base.value}/steps/${s.id}/toggle`, { done: !s.done }, opts); }
function assignStep(s, e) { router.put(`${base.value}/steps/${s.id}`, { assignee_id: e.target.value || null }, opts); }
function setStepAction(s, e) { router.put(`${base.value}/steps/${s.id}`, { action: e.target.value || null }, opts); }
function setStepText(s, text) { router.put(`${base.value}/steps/${s.id}`, { text: (text || '').trim() || null }, opts); }
function setStepLinks(s, links) { router.put(`${base.value}/steps/${s.id}`, { links }, opts); } // link directive's target portal(s)
function setStepMods(s, mods) { router.put(`${base.value}/steps/${s.id}`, { mods: mods || null }, opts); }
function setStepQty(s, qty) { router.put(`${base.value}/steps/${s.id}`, { qty: +qty || null }, opts); } // count-based directive (farm keys)
function removeStep(s) { confirmAction(`Delete directive${s.text ? ` “${s.text}”` : ''}?`, () => router.delete(`${base.value}/steps/${s.id}`, opts), 'removeStep'); }
const doneWpIds = computed(() => props.steps.filter((s) => s.done && s.op_waypoint_id).map((s) => s.op_waypoint_id));

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

// Operator assigns/clears an agent's colour (#rrggbb or null) — drives their beacon/route + avatar ring.
function setAgentColor(p, color) { router.put(`${base.value}/participants/${p.user_id}/color`, { color }, opts); }

// ---- roster: add by callsign · kick · ban (operative) ----
const newAgent = ref('');
const agentResults = ref([]);
let at;
function searchAgents() {
    clearTimeout(at);
    at = setTimeout(async () => {
        if (newAgent.value.trim().length < 2) { agentResults.value = []; return; }
        const { data } = await window.axios.get('/api/agents/search', { params: { q: newAgent.value.trim() } });
        agentResults.value = data;
    }, 200);
}
function addAgent(callsign) {
    const cs = (callsign || newAgent.value).trim();
    if (!cs) return;
    router.post(`${base.value}/participants`, { callsign: cs }, { ...opts, onSuccess: () => { newAgent.value = ''; agentResults.value = []; } });
}
function kickAgent(p) { confirmAction(`Remove ${p.callsign} from the op?`, () => router.delete(`${base.value}/participants/${p.user_id}`, opts), 'kick'); }
function banAgent(p) { confirmAction(`Ban ${p.callsign}? They won't be able to rejoin this op.`, () => router.post(`${base.value}/participants/${p.user_id}/ban`, {}, opts), 'ban'); }
// unban is the recovery action (lifts the block; doesn't re-add them) — one tap, no confirm, like undo
function unbanAgent(b) { router.delete(`${base.value}/participants/${b.user_id}/ban`, opts); }
function promoteAgent(p) { confirmAction(`Promote ${p.callsign} to Operator? They'll be able to build and run the op.`, () => router.post(`${base.value}/participants/${p.user_id}/promote`, {}, opts), 'promote'); }
function demoteAgent(p) { confirmAction(`Demote ${p.callsign} to Agent? They'll lose Operator access.`, () => router.post(`${base.value}/participants/${p.user_id}/demote`, {}, opts), 'demote'); }

// Everything the widgets need, injected so each widget stays a thin presentational shell.
provide('opctx', {
    // getters so c.manage / c.editable unwrap to reactive booleans in widget templates
    // (a plain injected object does NOT auto-unwrap nested refs — c.manage would be truthy always)
    data: props,
    get manage() { return manage.value; },
    get editable() { return editable.value; },
    get canFan() { return canFan.value; }, // enable-gate for the auto button (fan geometry present)
    get focus() { return focus.value; },   // the full-page view key, or null on the dashboard
    me, share, dropMode, doneWpIds,
    onMapDrop, addFromCatalog, addLocation, attachPortal, renameWaypoint, flagPortal, saveIntel, reorderWaypoints, reorderSteps, setRole, removeWp, setKeyHeld, setKeyNeeded, autoFan, importPlan, saveNotes,
    addTaskAt, addTaskToAll, toggleStep, assignStep, setStepAction, setStepText, setStepLinks, setStepMods, setStepQty, removeStep, clearDirectives, clearWaypoints,
    saveTemplate, applyTemplate, deleteTemplate,
    selection, select, clearSelection,
    routeAgent, showRoute,
    newAgent, agentResults, searchAgents, addAgent, kickAgent, banAgent, unbanAgent, promoteAgent, demoteAgent, setAgentColor,
    openReport,
});

// ---- customizable widget grid (per-user; desktop + mobile) ----
const grid = reactive({
    desktop: props.layouts.desktop.map((i) => ({ ...i })),
    mobile: props.layouts.mobile.map((i) => ({ ...i })),
});
const invitePop = ref(false);   // invite-link popover in the top bar
const deviceMode = ref('desktop'); // follows the viewport
const layoutNonce = ref(0);        // bump to force the grid to adopt a collapse without remounting
const activeLayout = computed(() => grid[deviceMode.value]);

// ---- mobile: per-widget "widget ⇄ page" + arrow reorder (no dragging) ----
// Publish each grid's widget set so the menu (AppLayout) knows which widgets aren't loaded → page links.
function publishGridKeys() {
    const mobile = grid.mobile.map((i) => i.i);
    const desktop = grid.desktop.map((i) => i.i);
    try {
        localStorage.setItem('toady:mobileGrid', JSON.stringify(mobile));
        localStorage.setItem('toady:desktopGrid', JSON.stringify(desktop));
    } catch (e) { /* */ }
    // tell the kebab (AppLayout) the LIVE grid so its page-links always match what's actually on the grid
    window.dispatchEvent(new CustomEvent('toady:grids', { detail: { mobile, desktop } }));
}
// Save a grid to the server as a quiet background request (plain axios, NOT an Inertia visit) so it
// never triggers the progress bar — that way maximize/minimize shows a single bar for the navigation,
// not one for the save + one for the nav. publishGridKeys() first so the menu's page links update now.
function saveLayout(mode) {
    publishGridKeys();
    window.axios.put('/dashboard/layout', { op_id: props.op.id, mode, layout: grid[mode] }).catch(() => {});
}
const nextY = (items) => items.reduce((a, i) => Math.max(a, i.y + i.h), 0);
// Moving a widget in/out of `grid` already flips `focus` (it depends on onGrid()), so the view switches
// instantly from local state. Navigate RIGHT AWAY (never gated on the background save) — gating it on the
// axios callback let a slow save fire router.visit after the user had already moved on, bouncing them
// back. preserveState so Inertia doesn't remount the page (which would mount/slide the dashboard twice).
function makePage(key) {            // grid widget → full page (current device grid), then jump to it
    const mode = deviceMode.value;
    grid[mode] = grid[mode].filter((i) => i.i !== key);
    saveLayout(mode);
    router.visit(`${base.value}?view=${key}`, { preserveState: true, preserveScroll: true });
}
function toPage(key) {              // grid widget → page (removed from grid) but STAY on the dashboard (no navigate)
    const mode = deviceMode.value;
    grid[mode] = grid[mode].filter((i) => i.i !== key);
    saveLayout(mode);
}
function makeWidget(key) {          // full page → grid widget (current device grid), back to the dashboard
    const mode = deviceMode.value;
    if (!grid[mode].some((i) => i.i === key)) {
        const cat = props.widgetCatalog[key] || {};
        grid[mode] = [...grid[mode], { i: key, x: 0, y: nextY(grid[mode]), w: mode === 'mobile' ? 12 : (cat.w || 6), h: cat.h || 5 }];
    }
    saveLayout(mode);
    router.visit(base.value, { preserveState: true, preserveScroll: true });
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
    publishGridKeys(); // immediate, so the menu's page links update before the debounced server save
    const mode = deviceMode.value;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => saveLayout(mode), 600);
}
function onLayoutUpdate(next) { grid[deviceMode.value] = next; persist(); }

// Track the real viewport so we show the matching layout (and the menu's page links).
let mql;
function syncDevice() { deviceMode.value = mql?.matches ? 'mobile' : 'desktop'; }
onMounted(() => {
    publishGridKeys(); // tell the kebab menu which widgets are on the grid (the rest are page links)
    if (!window.matchMedia) return;
    mql = window.matchMedia('(max-width: 767px)');
    syncDevice();
    mql.addEventListener('change', syncDevice);
});
onBeforeUnmount(() => { mql?.removeEventListener('change', syncDevice); clearTimeout(saveTimer); });

// Mobile full pages (hamburger → ?view=…): every widget except the Map + Mission grid, reused full-screen.
const focusViews = Object.fromEntries(OP_VIEWS.map((v) => [v.key, v.label]));
const focusScroll = OP_VIEWS.filter((v) => v.scroll).map((v) => v.key); // list views scroll; the rest manage their own height
const opOnlyViews = new Set(OP_VIEWS.filter((v) => v.op).map((v) => v.key)); // operator-only full pages (activity log)
const targetView = computed(() => {
    const v = new URLSearchParams(page.url.split('?')[1] || '').get('view');
    return focusViews[v] && (!opOnlyViews.has(v) || manage.value) ? v : null;
});
const onGrid = (key) => activeLayout.value.some((i) => i.i === key);
// A view notification opens the full page when that widget ISN'T on the dashboard; when it IS, we
// stay on the dashboard and scroll to it (handled below).
const focus = computed(() => (targetView.value && !onGrid(targetView.value) ? targetView.value : null));

function scrollToTargetWidget() {
    const key = targetView.value;
    if (!key || !onGrid(key)) return;
    nextTick(() => setTimeout(() => document.getElementById('w-' + key)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 140));
}
onMounted(scrollToTargetWidget);
watch(() => page.url, scrollToTargetWidget);
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

        <!-- op action icons (right) — desktop top bar; on mobile they move into the kebab (#op-menu) -->
        <template #actions>
            <div class="hidden sm:flex items-center gap-0.5">
            <button v-if="manage" @click="undo" :disabled="op.status !== 'planning' || !op.undo_count"
                :class="barIcon" class="disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:bg-transparent disabled:hover:text-ink-dim"
                :title="op.status !== 'planning' ? 'Undo is only available while planning' : (op.undo_count ? `Undo the last plan change · ${op.undo_count} available` : 'Nothing to undo')">
                <Undo2 :size="17" />
            </button>
            <button v-if="manage" @click="invitePop = true" :class="barIcon" title="invite agents (link + QR)"><Share2 :size="17" /></button>
            <button v-if="manage" @click="editing = true" :class="barIcon" title="edit op"><Pencil :size="17" /></button>
            <a v-if="op.is_operative || op.allow_export" :href="`/ops/${op.id}/export`" :class="barIcon" title="export plan"><Download :size="17" /></a>
            <button v-if="manage" @click="closeOp" :class="barIconDanger" title="close + purge op"><X :size="17" /></button>
            <button v-if="!isOwner" @click="leaveOp" :class="barIconDanger" title="leave op"><LogOut :size="17" /></button>
            </div>
        </template>

        <!-- mobile: the same op actions, as an icon bar inside the kebab menu -->
        <template #op-menu="{ close }">
            <div class="flex items-center gap-0.5 px-2 py-1.5">
                <button v-if="manage" @click="close(); undo()" :disabled="op.status !== 'planning' || !op.undo_count" :class="barIcon" class="disabled:opacity-30 disabled:cursor-not-allowed" :title="op.status !== 'planning' ? 'Undo is only available while planning' : (op.undo_count ? `Undo the last plan change · ${op.undo_count} available` : 'Nothing to undo')"><Undo2 :size="17" /></button>
                <button v-if="manage" @click="close(); invitePop = true" :class="barIcon" title="invite agents (link + QR)"><Share2 :size="17" /></button>
                <button v-if="manage" @click="close(); editing = true" :class="barIcon" title="edit op"><Pencil :size="17" /></button>
                <a v-if="op.is_operative || op.allow_export" :href="`/ops/${op.id}/export`" @click="close()" :class="barIcon" title="export plan"><Download :size="17" /></a>
                <button v-if="manage" @click="close(); closeOp()" :class="barIconDanger" title="close + purge op"><X :size="17" /></button>
                <button v-if="!isOwner" @click="close(); leaveOp()" :class="barIconDanger" title="leave op"><LogOut :size="17" /></button>
                <!-- op status: one button that cycles planning → active → complete, its icon + colour showing the current status -->
                <button v-if="manage" @click="cycleStatus" :title="`Status: ${op.status} — tap to cycle`"
                    class="flex items-center justify-center w-9 h-8 rounded border ml-0.5" :class="STATUS_BTN[op.status]">
                    <component :is="STATUS_ICON[op.status]" :size="17" />
                </button>
                <span v-else class="inline-flex items-center gap-1 text-[10px] font-mono uppercase border rounded px-1.5 py-0.5" :class="STATUS_PILL[op.status]"><component :is="STATUS_ICON[op.status]" :size="12" /> {{ op.status }}</span>
            </div>
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

        <!-- focused mobile page — header toggle (⤡) pins it back into the dashboard grid -->
        <div v-else-if="focus" class="h-[calc(100dvh-5.5rem)]">
            <DashboardWidgetCard :title="focusViews[focus]" :icon="WIDGET_ICONS[focus]" :scroll="focusScroll.includes(focus)"
                :can-toggle-mode="true" mode="page" @toggle-mode="makeWidget(focus)">
                <WidgetRenderer :widget="focus" />
            </DashboardWidgetCard>
        </div>

        <template v-else>
            <div v-if="!activeLayout.length" class="text-center py-24 text-ink-faint text-sm leading-relaxed">
                <p>Every panel is open as a full page.</p>
                <p class="mt-1">Open one from the menu and tap <Minimize2 :size="13" class="inline align-text-bottom" /> to dock it back here.</p>
            </div>
            <DashboardGrid v-else :key="deviceMode" class="-mt-3" :layout="activeLayout" :sync-key="layoutNonce" :catalog="widgetCatalog"
                :mobile="deviceMode === 'mobile'"
                @update:layout="onLayoutUpdate" @toggle-mode="makePage" @to-page="toPage" @toggle-collapse="collapseWidget" />
        </template>

        <!-- edit op modal -->
        <div v-if="editing" class="fixed inset-0 z-50 flex items-start justify-center p-4 pt-20 bg-black/60" @click.self="editing = false">
            <form @submit.prevent="saveHeader" class="w-full max-w-lg border border-line rounded-lg bg-surface p-3 space-y-2 shadow-2xl">
                <div class="text-xs font-mono uppercase tracking-wide text-ink-dim">Edit op</div>
                <p v-if="!editable" class="text-[11px] font-mono text-amber-300/80">Title, briefing &amp; type are locked while the op is active — set it back to planning to edit them.</p>
                <input v-model="header.name" :disabled="!editable" class="w-full bg-inset border border-line rounded px-1.5 py-2 text-sm disabled:opacity-50 disabled:cursor-not-allowed" />
                <textarea v-model="header.description" spellcheck="true" :disabled="!editable" rows="2" placeholder="description / directives" class="w-full bg-inset border border-line rounded px-1.5 py-2 text-sm disabled:opacity-50 disabled:cursor-not-allowed"></textarea>
                <textarea v-model="header.goals" spellcheck="true" :disabled="!editable" rows="2" placeholder="goals (shown in the Goals & notes widget)" class="w-full bg-inset border border-line rounded px-1.5 py-2 text-sm disabled:opacity-50 disabled:cursor-not-allowed"></textarea>
                <label class="flex items-center gap-2 text-xs font-mono text-ink-dim">
                    <input type="checkbox" v-model="header.allow_export" class="accent-emerald-500" /> let agents export this plan
                </label>
                <div class="flex flex-wrap gap-2 items-center">
                    <select v-model="header.type" :disabled="!editable" class="bg-inset border border-line rounded px-1.5 py-2 text-sm font-mono disabled:opacity-50 disabled:cursor-not-allowed">
                        <option value="any_order">any order</option><option value="visible">sequential · visible</option><option value="hidden">sequential · hidden</option>
                    </select>
                    <button type="submit" class="bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-xs font-semibold rounded px-1.5 py-1.5">save</button>
                    <button type="button" @click="editing = false" class="text-xs text-ink-faint px-2">cancel</button>
                    <button type="button" @click="closeOp" class="ml-auto text-xs text-ink-faint hover:text-rose-400 font-mono">close + purge op</button>
                </div>
            </form>
        </div>

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

        <!-- destructive-action confirm — in-app so the "don't ask again" actually works (and is remembered) -->
        <div v-if="confirmModal.open" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60" @click.self="confirmNo">
            <div class="w-full max-w-sm border border-line rounded-lg bg-surface p-4 shadow-2xl">
                <p class="text-sm text-ink">{{ confirmModal.message }}</p>
                <label v-if="confirmModal.key" class="mt-3 flex items-center gap-2 text-xs font-mono text-ink-dim cursor-pointer select-none">
                    <input type="checkbox" v-model="confirmModal.remember" class="accent-emerald-500" /> don't ask again
                </label>
                <div class="mt-4 flex items-center justify-end gap-2">
                    <button @click="confirmNo" class="text-sm font-mono text-ink-faint hover:text-ink px-3 py-1.5">cancel</button>
                    <button @click="confirmYes" class="bg-rose-500/80 hover:bg-rose-500 text-white font-mono text-sm font-semibold rounded px-3 py-1.5">confirm</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
