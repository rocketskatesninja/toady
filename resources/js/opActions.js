import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';

// Every plan / directive / task / roster action the op's widgets can take — one-line requests against the op's
// routes, handed to the widgets through the injected op context (Show.vue). `confirmAction(message, action, key?)`
// is the page's in-app confirm dialog.
export function useOpActions(props, confirmAction) {
    const opts = { preserveScroll: true };
    const base = computed(() => `/ops/${props.op.id}`);

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
    // operatives add op-local portal intel (purged with the op) while planning
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
    function clearKeys() {
        confirmAction('Clear every agent’s held-key counts? Key targets (the “need” values) stay — only who-holds-what is wiped. You can undo this.',
            () => router.post(`${base.value}/keys/clear`, {}, opts));
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
    // a new directive's action-specific second field, from the widget's draft: link → target(s), mod → which, farm keys → how many
    function draftDetail(draft) {
        if (draft.action === 'link' && draft.links?.length) return { links: draft.links };
        if (draft.action === 'mod' && draft.mods) return { mods: draft.mods };
        if (draft.action === 'farm keys' && draft.qty) return { qty: +draft.qty };
        return {};
    }
    // add a directive under a location; draft = reactive { text, action, assignee_id, withComment, … } owned by the widget
    function addTaskAt(wpId, draft) {
        const t = (draft.text || '').trim();
        if (!t && !draft.action) return; // need an objective and/or a comment
        router.post(`${base.value}/steps`, { text: t || null, action: draft.action || null, op_waypoint_id: wpId, assignee_id: draft.assignee_id || null, ...draftDetail(draft) },
            { ...opts, onSuccess: () => { draft.text = ''; draft.withComment = false; draft.links = []; draft.mods = ''; draft.qty = null; } });
    }
    // the same quick-add, applied to every portal at once ("to all") — one atomic request, one undo entry
    function addTaskToAll(draft) {
        if (draft.action) router.post(`${base.value}/steps/bulk`, { action: draft.action, assignee_id: draft.assignee_id || null, ...draftDetail(draft) }, opts);
    }
    const putStep = (s, data) => router.put(`${base.value}/steps/${s.id}`, data, opts);
    function toggleStep(s) { router.put(`${base.value}/steps/${s.id}/toggle`, { done: !s.done }, opts); }
    function assignStep(s, e) { putStep(s, { assignee_id: e.target.value || null }); }
    function setStepAction(s, e) { putStep(s, { action: e.target.value || null }); }
    function setStepText(s, text) { putStep(s, { text: (text || '').trim() || null }); }
    function setStepLinks(s, links) { putStep(s, { links }); } // link directive's target portal(s)
    function setStepMods(s, mods) { putStep(s, { mods: mods || null }); }
    function setStepQty(s, qty) { putStep(s, { qty: +qty || null }); } // count-based directive (farm keys)
    function removeStep(s) { confirmAction(`Delete directive${s.text ? ` “${s.text}”` : ''}?`, () => router.delete(`${base.value}/steps/${s.id}`, opts), 'removeStep'); }

    // Tasks widget (a plain checklist — not the plan's directives)
    function addOpTask(text, assigneeId) { router.post(`${base.value}/tasks`, { text, assignee_id: assigneeId || null }, opts); }
    function toggleOpTask(t) { router.put(`${base.value}/tasks/${t.id}/toggle`, { done: !t.done }, opts); }
    function assignOpTask(t, e) { router.put(`${base.value}/tasks/${t.id}`, { assignee_id: e.target.value || null }, opts); }
    function removeOpTask(t) { confirmAction(`Delete task “${t.text}”?`, () => router.delete(`${base.value}/tasks/${t.id}`, opts), 'removeOpTask'); }

    // Operator assigns/clears an agent's colour (#rrggbb or null) — drives their beacon/route + avatar ring.
    function setAgentColor(p, color) { router.put(`${base.value}/participants/${p.user_id}/color`, { color }, opts); }

    // ---- roster: kick · ban · promote (operative). Agents join via the invite link/QR only. ----
    function kickAgent(p) { confirmAction(`Remove ${p.callsign} from the op?`, () => router.delete(`${base.value}/participants/${p.user_id}`, opts), 'kick'); }
    function banAgent(p) { confirmAction(`Ban ${p.callsign}? They won't be able to rejoin this op.`, () => router.post(`${base.value}/participants/${p.user_id}/ban`, {}, opts), 'ban'); }
    // unban is the recovery action (lifts the block; doesn't re-add them) — one tap, no confirm, like undo
    function unbanAgent(b) { router.delete(`${base.value}/participants/${b.user_id}/ban`, opts); }
    function promoteAgent(p) { confirmAction(`Promote ${p.callsign} to Operator? They'll be able to build and run the op.`, () => router.post(`${base.value}/participants/${p.user_id}/promote`, {}, opts), 'promote'); }
    function demoteAgent(p) { confirmAction(`Demote ${p.callsign} to Agent? They'll lose Operator access.`, () => router.post(`${base.value}/participants/${p.user_id}/demote`, {}, opts), 'demote'); }

    return {
        dropMode, canFan, onMapDrop, addFromCatalog, addLocation, attachPortal, renameWaypoint, flagPortal, saveIntel, reorderWaypoints, reorderSteps, setRole, removeWp, setKeyHeld, setKeyNeeded, autoFan, importPlan, undo,
        addTaskAt, addTaskToAll, toggleStep, assignStep, setStepAction, setStepText, setStepLinks, setStepMods, setStepQty, removeStep, clearDirectives, clearWaypoints, clearKeys,
        addOpTask, toggleOpTask, assignOpTask, removeOpTask,
        saveTemplate, applyTemplate, deleteTemplate,
        kickAgent, banAgent, unbanAgent, promoteAgent, demoteAgent, setAgentColor,
    };
}
