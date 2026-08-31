<script setup>
import { inject, ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { usePoll } from '@/useLive';
import { computeCycle } from '@/cycle';
import { TriangleAlert, Info, Lightbulb, Sunset, KeyRound, MapPin, Target, UserPlus, CarFront, CloudRain, CheckCircle2, TimerReset, Unlink } from 'lucide-vue-next';
import { haversineMeters } from '@/geo';
import { soloMaxLinkMeters, l8MaxLinkMeters } from '@/mechanics';

const c = inject('opctx');
const opId = c.data.op.id;
const page = usePage();

// tick for the time-based cycle advisories (checkpoint imminent / cycle ending); coarse — 15s is plenty
const cycleNow = ref(Date.now());
let cycleTick;
onMounted(() => { cycleTick = setInterval(() => { cycleNow.value = Date.now(); }, 15000); });
onBeforeUnmount(() => clearInterval(cycleTick));

// --- weather (fetched; readiness comes straight from the op payload, which already polls) ---
const wx = ref(null);
async function loadWeather() {
    try { const { data } = await window.axios.get(`/ops/${opId}/weather`); wx.value = data; } catch (e) { /* keep last */ }
}
usePoll(loadWeather, 600000); // every 10 min — weather moves slowly

const clock = (iso) => { try { return new Date(iso).toLocaleTimeString([], { hour: 'numeric' }); } catch (e) { return ''; } };
const plural = (n, w) => `${n} ${w}${n === 1 ? '' : 's'}`;
// name the actual waypoints in an advisory (up to two, then "+N more") so it points you at the portal, not just a count
const listNames = (wps) => {
    const n = wps.map((w) => `“${w.title || 'untitled'}”`);
    if (!n.length) return '';
    if (n.length === 1) return n[0];
    if (n.length === 2) return `${n[0]} and ${n[1]}`;
    return `${n[0]}, ${n[1]} and ${n.length - 2} more`;
};

// --- readiness advisories (live from c.data) ---
const readiness = computed(() => {
    const out = [];
    const op = c.data.op;
    const steps = c.data.steps || [];
    const wps = c.data.waypoints || [];
    const parts = c.data.participants || [];

    if (op.status === 'planning') out.push({ sev: 'info', icon: Info, msg: 'Op is in planning — flip it to active when you’re ready to launch.', view: null });
    if (parts.length <= 1) out.push({ sev: 'amber', icon: UserPlus, msg: 'No agents have joined yet — share the invite link.', view: 'roster' });
    if (!steps.length) out.push({ sev: 'amber', icon: Target, msg: 'No directives yet — build the mission.', view: 'plan' });

    const unassigned = steps.filter((s) => !s.done && !s.assignee_id).length;
    if (unassigned) out.push({ sev: 'amber', icon: TriangleAlert, msg: `${plural(unassigned, 'directive')} unassigned — nobody’s on ${unassigned === 1 ? 'it' : 'them'}.`, view: 'plan' });

    const keysShort = wps.reduce((sum, w) => {
        const need = w.keys_needed || 0;
        if (!need) return sum;
        const held = (c.data.keyHoldings || []).filter((h) => h.op_waypoint_id === w.id).reduce((s, h) => s + h.qty, 0);
        return sum + Math.max(0, need - held);
    }, 0);
    if (keysShort) out.push({ sev: 'amber', icon: KeyRound, msg: `${plural(keysShort, 'key')} still to farm across the plan.`, view: 'keys' });

    const unplaced = wps.filter((w) => w.lat == null);
    if (unplaced.length) out.push({ sev: 'amber', icon: MapPin, msg: `${listNames(unplaced)} ${unplaced.length === 1 ? 'has' : 'have'} no portal pinned — can’t navigate there.`, view: 'plan' });

    const noDir = wps.filter((w) => !steps.some((s) => s.op_waypoint_id === w.id));
    if (noDir.length) out.push({ sev: 'info', icon: Info, msg: `${listNames(noDir)} ${noDir.length === 1 ? 'has' : 'have'} no directives yet.`, view: 'plan' });

    const missingSteps = steps.filter((s) => !s.done && ((s.action === 'link' && !(s.links && s.links.length)) || (s.action === 'mod' && !s.mods) || (s.action === 'farm keys' && !s.qty)));
    if (missingSteps.length) {
        const onWps = [...new Set(missingSteps.map((s) => s.op_waypoint_id))].map((id) => wps.find((w) => w.id === id)).filter(Boolean);
        const where = onWps.length ? ` on ${listNames(onWps)}` : '';
        out.push({ sev: 'info', icon: TriangleAlert, msg: `${plural(missingSteps.length, 'directive')}${where} ${missingSteps.length === 1 ? 'is' : 'are'} missing details (link target, mod, or key count).`, view: 'plan' });
    }

    if (op.status === 'active') {
        const sharing = (c.data.presence || []).filter((p) => p.lat != null).length;
        const notSharing = parts.length - sharing;
        if (notSharing > 0 && parts.length > 1) out.push({ sev: 'info', icon: MapPin, msg: `${notSharing} of ${parts.length} ${notSharing === 1 ? 'agent isn’t' : 'agents aren’t'} sharing location.`, view: 'roster' });

        const dones = steps.filter((s) => s.done && s.done_at).map((s) => new Date(s.done_at).getTime());
        const lastDone = dones.length ? Math.max(...dones) : null;
        if (lastDone && steps.some((s) => !s.done)) {
            const mins = Math.round((Date.now() - lastDone) / 60000);
            if (mins >= 20) out.push({ sev: 'amber', icon: TriangleAlert, msg: `No directive completed in ${mins} min — the op may be stalled.`, view: null });
        }

        // soft traffic nudge (no live incident feed — points at the map's traffic layer)
        if (wps.filter((w) => w.lat != null).length > 1) out.push({ sev: 'info', icon: CarFront, msg: 'Driving between portals? Check live traffic — toggle the traffic layer on the map.', view: 'map' });
    }
    return out;
});

// --- link-range advisories: catch planned links that are physically un-throwable ---
// A portal's link range is 160 m × (avg reso level)⁴. A solo agent tops out ~160 km; even a fully-built
// L8 portal reaches ~655 km. Any planned link longer than the solo max needs a specially-built portal or a
// Link Amp — worth flagging before the field, since it rarely surfaces until someone's standing there.
const SOLO_MAX = soloMaxLinkMeters();
const L8_MAX = l8MaxLinkMeters();
const km = (m) => (m / 1000).toFixed(m < 10000 ? 1 : 0);
const rangeAdv = computed(() => {
    const out = [];
    const byId = {};
    for (const w of c.data.waypoints || []) if (w.lat != null) byId[w.id] = w;

    const tooFar = [];
    for (const s of c.data.steps || []) {
        if (s.action !== 'link' || !Array.isArray(s.links)) continue;
        const from = byId[s.op_waypoint_id];
        if (!from) continue;
        for (const tid of s.links) {
            const to = byId[tid];
            if (!to) continue;
            const m = haversineMeters([from.lng, from.lat], [to.lng, to.lat]);
            if (m > SOLO_MAX) tooFar.push({ from, to, m });
        }
    }
    if (!tooFar.length) return out;

    tooFar.sort((a, b) => b.m - a.m);
    const names = tooFar.slice(0, 2).map((l) => `“${l.from.title || 'untitled'}”→“${l.to.title || 'untitled'}” (${km(l.m)} km)`).join(', ');
    const more = tooFar.length > 2 ? ` +${tooFar.length - 2} more` : '';
    const beyondL8 = tooFar.some((l) => l.m > L8_MAX);
    const tail = beyondL8
        ? 'past even a fully-built L8 portal’s reach — you’ll need a Link Amp.'
        : 'beyond a solo agent’s reach — build the origin to L6+ or use a Link Amp.';
    out.push({ sev: 'amber', icon: Unlink, msg: `${plural(tooFar.length, 'planned link')} out of range: ${names}${more} — ${tail}`, view: 'plan' });
    return out;
});

// --- weather advisories (from the fetched forecast) ---
const weatherAdv = computed(() => {
    const out = [];
    const w = wx.value;
    if (!w || !w.ok) return out;

    if (w.sun?.sunset && w.sun.is_day) {
        const mins = Math.round((new Date(w.sun.sunset).getTime() - Date.now()) / 60000);
        if (mins > 0 && mins <= 45) out.push({ sev: 'amber', icon: Sunset, msg: `Sunset in ~${mins} min — portals get hard to spot after dark.`, view: 'weather' });
    } else if (w.sun && w.sun.is_day === false) {
        out.push({ sev: 'info', icon: Sunset, msg: 'It’s dark out — portals are harder to find; bring a light.', view: 'weather' });
    }

    const rainy = (w.hourly || []).slice(0, 4).find((h) => (h.precip ?? 0) >= 50 || /rain|storm|thunder|shower|snow/i.test(h.short || ''));
    if (rainy) out.push({ sev: 'amber', icon: CloudRain, msg: `Wet weather likely (${rainy.short || rainy.precip + '%'}) around ${clock(rainy.time)}.`, view: 'weather' });

    const t = w.hourly?.[0];
    if (t && t.unit === 'F') {
        if (t.temp >= 95) out.push({ sev: 'amber', icon: TriangleAlert, msg: `Heat: ${t.temp}° out — hydrate and pace the team.`, view: 'weather' });
        else if (t.temp <= 32) out.push({ sev: 'amber', icon: TriangleAlert, msg: `Cold: ${t.temp}° out — bundle up and watch for ice.`, view: 'weather' });
    }
    return out;
});

// --- cycle advisories (global Ingress checkpoint/cycle timing; from page.props.cycle) ---
const cycleAdv = computed(() => {
    const out = [];
    const m = computeCycle(page.props.cycle, cycleNow.value);
    if (!m || m.pending) return out;

    // checkpoint imminent — within 10 minutes: throw now so the MU banks this cycle
    if (m.toNextCp <= 10 * 60000) {
        const mins = Math.max(1, Math.round(m.toNextCp / 60000));
        out.push({ sev: 'amber', icon: TimerReset, msg: `Checkpoint in ~${mins} min — close your fields now to bank the MU this cycle.`, view: 'cycle' });
    }
    // cycle ending — within 24 hours (round to whole minutes without a "60m" rollover)
    if (m.toCycleEnd <= 24 * 3600000) {
        const totalMin = Math.round(m.toCycleEnd / 60000);
        const h = Math.floor(totalMin / 60);
        const min = totalMin % 60;
        out.push({ sev: 'info', icon: TimerReset, msg: `Cycle ${m.label} ends in ${h > 0 ? `${h}h ${min}m` : `${min}m`}.`, view: 'cycle' });
    }
    return out;
});

const sevRank = { red: 0, amber: 1, info: 2 };
const sevColor = (sev) => (sev === 'red' ? 'text-rose-400' : sev === 'amber' ? 'text-amber-300' : 'text-sky-400/70');
const advisories = computed(() => [...readiness.value, ...rangeAdv.value, ...weatherAdv.value, ...cycleAdv.value].sort((a, b) => sevRank[a.sev] - sevRank[b.sev]));

// --- rotating UI tips ---
const tips = [
    // --- using toady ---
    'Press & hold a widget’s header to drag it to a new spot.',
    'Tap a portal on the map to highlight it in the list — and vice-versa.',
    'The map auto-zooms to a portal when you select it — tap the crosshair under “find my location” to turn that off.',
    'Tap the padlock on the map to lock it in place — no more accidental panning while you work, and it stays put across refreshes.',
    'Type @callsign in comms to ping someone directly.',
    'Save a location’s directives as a template, then drop it onto the next portal.',
    'Reorder directives with their ↑/↓ arrows; drag location cards to reorder those.',
    'Toggle satellite, radar, route, links, and traffic right on the map.',
    'Pick an agent in the Roster to draw their route — it shows the walking distance and on-foot ETA.',
    'On the map, hit “optimize walk” to reorder an agent’s farm/capture stops into the shortest route — link throws keep their fielding order.',
    'Invite agents by link or QR — open the invite and let them scan to join the op.',
    'Set keys needed in the Recon panel; agents tap −/+ to report what they hold.',
    // --- checkpoints & cycles ---
    'Add the Cycle widget for a live countdown to the next checkpoint and the current cycle’s end — no scanner needed.',
    'Checkpoints land every 5 hours; throw your fields just before one so the MU banks for this cycle.',
    'Cycle and checkpoint times show in each agent’s own local timezone automatically.',
    'Collapse, resize, or add widgets to make the war-room yours.',
    'Export your plan before closing an op — closing purges everything for good.',
    'Paste an Intel or Google Maps link to drop a portal by its coordinates.',
    'Import an IITC Draw Tools or Bookmarks plan to add many portals at once.',
    'Run a sequential op to lock directives in order, or “any order” to let agents roam.',
    'Hidden ops reveal each waypoint only after the one before it is cleared.',
    'Assign a directive to an agent, or leave it “anyone” for whoever’s closest.',
    'Opt in to live location per op — it shows you on the map and goes stale when you stop.',
    'Open any widget as a full page with ⤢, then dock it back the same way.',
    'A portal’s marker swaps its number for a ✓ once every directive there is done.',
    'Thrown links go solid on the map; pending stay dashed; completed fields shade in.',
    'The after-action report tallies AP, links, fields, and each agent’s work — open and download it (.txt/.json) any time before you close the op.',
    'Add optional contact and emergency info in your profile — visible only to your op.',
    'Tap the sun/moon in the menu for a high-contrast daylight screen in bright sun.',
    'On a phone? Switch to “Big View” in the menu to zoom the whole UI for easier, fat-finger taps.',
    'Pull portals from the master catalog, or add op-local ones that vanish with the op.',
    'Tune which alerts buzz you in the Notifications panel; the header bell jumps there.',
    'Your phone and desktop dashboards are separate — arrange each to taste.',
    'Advisories flags gaps: unassigned directives, key shortfalls, unplaced or empty portals.',
    // --- ingress field craft ---
    'Ingress: to link to a portal you need its key — hack the destination first to get one.',
    'Ingress: links can’t cross other links or fields, so plan your layering order.',
    'Ingress: a control field needs three mutually-linked portals — the third link closes it.',
    'Ingress: throw your outer links first — you can’t link out through a field.',
    'Ingress: link range grows with a portal’s resonator levels, so deploy your highest.',
    'Ingress: a fan field links one anchor to a row of portals — many fields from one spot.',
    'Ingress: a Link Amp extends range; a SoftBank Ultra Link adds range and link capacity.',
    'Ingress: Heat Sinks cut hack cooldown; Multi-hacks add hacks before burnout.',
    'Ingress: hacking too fast trips burnout — space your hacks or add a Heat Sink.',
    'Ingress: Shields and Aegis add mitigation — stack them on your key anchors.',
    'Ingress: recharge your portals from anywhere to keep links and fields standing.',
    'Ingress: glyph-hacking earns bonus items and AP — learn the common sequences.',
    'Ingress: capture a neutral portal by deploying its first resonator.',
    'Ingress: every portal has 8 resonator slots and 4 mod slots.',
    'Ingress: bigger fields over denser areas capture more Mind Units (MU).',
    'Ingress: farm enough keys before the op — every link needs a key at its origin.',
    'Ingress: Force Amp and Turret help a portal fight back against attackers.',
    'Ingress: keys never expire — stockpile them ahead of a big fielding op.',
    'Ingress: stash keys in a capsule or key locker to free up inventory for a big op.',
    'Ingress: an ADA Refactor (RES) or Jarvis Virus (ENL) flips an enemy portal to your side.',
    'Ingress: higher-level portals reach farther and hit harder — level your anchors first.',
];
const tipIdx = ref(0);
let tipTimer;
onMounted(() => { tipTimer = setInterval(() => { tipIdx.value = (tipIdx.value + 1) % tips.length; }, 9000); });
onBeforeUnmount(() => clearInterval(tipTimer));
</script>

<template>
    <div class="px-2.5 pt-2 pb-3 flex flex-col gap-3 min-h-full">
        <div>
            <ul v-if="advisories.length" class="space-y-1">
                <li v-for="(a, i) in advisories" :key="i">
                    <component :is="a.view ? Link : 'div'" v-bind="a.view ? { href: `/ops/${opId}?view=${a.view}` } : {}"
                        class="flex items-start gap-2 text-xs rounded -mx-1 px-1 py-1" :class="a.view ? 'hover:bg-emerald-500/5' : ''">
                        <component :is="a.icon" :size="14" class="shrink-0 mt-0.5" :class="sevColor(a.sev)" />
                        <span class="flex-1 min-w-0 text-ink-dim">{{ a.msg }}</span>
                    </component>
                </li>
            </ul>
            <div v-else class="flex items-center gap-2 text-xs text-accent"><CheckCircle2 :size="14" class="shrink-0" /> All clear — nothing needs attention.</div>
        </div>

        <!-- rotating tip -->
        <div class="mt-auto border-t border-line/60 pt-2.5">
            <div class="flex items-start gap-2">
                <Lightbulb :size="14" class="shrink-0 mt-0.5 text-amber-300/80" />
                <span class="shrink-0 mt-0.5 text-[10px] font-mono uppercase tracking-wide text-ink-faint">Tip</span>
                <Transition name="tip-fade" mode="out-in">
                    <p :key="tipIdx" class="text-xs text-ink-dim flex-1 min-w-0">{{ tips[tipIdx] }}</p>
                </Transition>
            </div>
        </div>
    </div>
</template>

<style scoped>
.tip-fade-enter-active, .tip-fade-leave-active { transition: opacity 0.3s ease; }
.tip-fade-enter-from, .tip-fade-leave-to { opacity: 0; }
@media (prefers-reduced-motion: reduce) { .tip-fade-enter-active, .tip-fade-leave-active { transition: none; } }
</style>
