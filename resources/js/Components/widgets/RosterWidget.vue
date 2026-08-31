<script setup>
import { inject, ref, computed, onMounted, watch } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import { factionText, roleLabel } from '@/faction';
import { useSelectionSync } from '@/useSelection';
import { UserPlus, Boxes, Phone, MessageSquare, MessagesSquare, Send, UserMinus, Ban, MapPin, ArrowUpCircle, ArrowDownCircle, Route, Check, X, Palette, Bell, BellOff, RotateCcw } from 'lucide-vue-next';
import Avatar from '@/Components/Avatar.vue';

const c = inject('opctx');

// operator's per-agent colour palette (vivid + map-legible on the dark basemap); plus "clear" in the popover
const AGENT_COLORS = ['#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16', '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6', '#8b5cf6', '#d946ef', '#ec4899'];
// the colour popover is teleported to <body> + fixed-positioned at the button, so the agent card's
// overflow-hidden (rounded corners) can't clip it — it always spawns in the foreground.
const colorPop = ref({ id: null, top: 0, left: 0 });
const colorParticipant = computed(() => c.data.participants.find((p) => p.user_id === colorPop.value.id) || null);
function toggleColor(p, ev) {
    if (colorPop.value.id === p.user_id) { closeColor(); return; }
    const r = ev.currentTarget.getBoundingClientRect();
    colorPop.value = { id: p.user_id, top: r.bottom + 6, left: r.right }; // anchor below + right-aligned (popover shifts left via transform)
}
function closeColor() { colorPop.value = { id: null, top: 0, left: 0 }; }
function pickColor(p, col) { c.setAgentColor(p, col); closeColor(); }

// A single L8 agent fully deploying a portal solo fills all 8 slots at the per-agent deploy limits:
// 1×L8, 1×L7, 2×L6, 2×L5, 2×L4. We surface this per-level breakdown ×(portals they capture/deploy).
const RESO_PER_PORTAL = [{ level: 8, n: 1 }, { level: 7, n: 1 }, { level: 6, n: 2 }, { level: 5, n: 2 }, { level: 4, n: 2 }];
// canonical Ingress resonator-level colours (yellow → orange → red → magenta → purple)
const RESO_COLORS = { 4: '#E40000', 5: '#FD2992', 6: '#EB26CD', 7: '#C124E0', 8: '#9627E3' };

// Per-agent loadout ("kit") — what each agent needs to bring for their assigned directives.
// Assumes every agent is L8+ and working solo, so it's the minimal solo loadout, derived purely
// from existing directive data (no extra inputs):
//   resonators = the per-level breakdown above, ×(portals they capture/deploy)
//   mods/viri  = their mod directives + ada/jarvis directives, grouped by name
//   keys       = one per link, grouped by the link's target portal, flagged held/short vs. the
//                agent's own key holdings for that portal
const kits = computed(() => {
    const order = {};
    c.data.waypoints.forEach((w, i) => { order[w.id] = i; });
    const title = (id) => c.data.waypoints.find((w) => w.id === id)?.title || 'portal';
    const tally = (rows) => {
        const m = {};
        rows.forEach((n) => { m[n] = (m[n] || 0) + 1; });
        return Object.entries(m).map(([name, count]) => ({ name, count }));
    };
    const out = {};
    for (const p of c.data.participants) {
        const mine = c.data.steps.filter((s) => s.assignee_id === p.user_id);
        const resoPortals = new Set(mine.filter((s) => (s.action === 'capture' || s.action === 'deploy') && s.op_waypoint_id).map((s) => s.op_waypoint_id)).size;
        const resos = resoPortals ? RESO_PER_PORTAL.map((r) => ({ level: r.level, n: r.n * resoPortals })) : [];
        const mods = tally(mine.filter((s) => s.action === 'mod' && s.mods).map((s) => s.mods));
        const viri = tally(mine.filter((s) => s.action === 'ada' || s.action === 'jarvis').map((s) => s.action.toUpperCase()));
        const keyMap = {};
        mine.filter((s) => s.action === 'link' && Array.isArray(s.links)).forEach((s) => s.links.forEach((t) => { keyMap[t] = (keyMap[t] || 0) + 1; }));
        const held = (wpId) => c.data.keyHoldings.filter((h) => h.user_id === p.user_id && h.op_waypoint_id === wpId).reduce((sum, h) => sum + (h.qty || 0), 0);
        const keys = Object.entries(keyMap).map(([id, count]) => { const h = held(+id); return { id: +id, name: title(+id), count, held: h, have: h >= count }; }).sort((a, b) => (order[a.id] ?? 999) - (order[b.id] ?? 999));
        const portals = new Set(mine.filter((s) => s.op_waypoint_id).map((s) => s.op_waypoint_id));
        out[p.user_id] = {
            resos, resoPortals, resoTotal: resoPortals * 8, mods, viri, keys,
            routable: portals.size >= 2,
            empty: !resoPortals && !mods.length && !viri.length && !keys.length,
        };
    }
    return out;
});

// the op creator is untouchable; only the owner can manage other Operators
const iAmOwner = computed(() => c.me === c.data.op.owner_id);
function isOwnerP(p) { return p.user_id === c.data.op.owner_id; }

// open the matching card when an agent is picked on the map (no yank-scroll); skip the whole map↔list
// sync when this widget is a full-size page — there's no map on screen to sync with
const { openId, toggle } = useSelectionSync(c, 'user', 'agent-', (id) => c.data.participants.some((p) => p.user_id === id), false, () => c.focus !== 'roster');
function isLive(userId) { return c.data.presence.some((p) => p.user_id === userId); }
function joinedAt(iso) {
    try { return new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return iso; }
}

// deep link: ?agent=<userId> (e.g. a directive's assignee name) opens that agent's card + selects them
const page = usePage();
function openFromUrl() {
    const id = Number(new URLSearchParams(page.url.split('?')[1] || '').get('agent'));
    if (id && c.data.participants.some((p) => p.user_id === id)) { openId.value = id; c.select('user', id); }
}
onMounted(openFromUrl);
watch(() => page.url, openFromUrl);
</script>

<template>
    <div>
        <!-- add an agent by callsign (operator, planning only — like the other edit boxes) -->
        <div v-if="c.editable" class="relative px-1.5 py-2">
            <input v-model="c.newAgent.value" @input="c.searchAgents" @keyup.enter="c.addAgent()"
                placeholder="add agent by callsign…" class="w-full bg-inset border border-line rounded px-1.5 py-1.5 text-sm focus:border-accent focus:outline-none" />
            <ul v-if="c.agentResults.value.length" class="absolute left-1.5 right-1.5 z-20 mt-1 bg-surface border border-line rounded shadow-xl max-h-40 overflow-auto op-scroll">
                <li v-for="a in c.agentResults.value" :key="a.id">
                    <button @click="c.addAgent(a.callsign)" class="w-full text-left px-1.5 py-1.5 text-sm text-ink-dim hover:bg-emerald-500/10 hover:text-ink">
                        {{ a.callsign }} <span :class="factionText(a.faction)" class="text-xs">[{{ a.faction || '—' }}]</span>
                    </button>
                </li>
            </ul>
        </div>

        <!-- agent cards (accordion; expand to see info + select on the map) -->
        <div class="px-1.5 py-2 space-y-1">
            <div v-for="p in c.data.participants" :key="p.id" :id="'agent-' + p.user_id" class="border rounded-lg bg-surface overflow-hidden" :class="openId === p.user_id ? 'border-accent' : 'border-line/60'">
                <div class="flex items-center gap-2 px-2 py-2" :class="openId === p.user_id ? '' : 'hover:bg-emerald-500/5'">
                    <button @click="toggle(p.user_id)" class="flex items-center gap-2 min-w-0 flex-1 text-left">
                        <Avatar :src="p.avatar" :callsign="p.callsign" :faction="p.faction" :ring="p.color" :size="26" />
                        <!-- name + its status icons hugging it; the flex-1 wrapper keeps faction/role on the right -->
                        <span class="flex items-center gap-1.5 min-w-0 flex-1">
                            <span class="text-sm text-ink truncate min-w-0">{{ p.callsign }}</span>
                            <!-- status: notifications (operators only) + location sharing -->
                            <span class="flex items-center gap-1 shrink-0">
                                <component :is="p.push ? Bell : BellOff" v-if="p.push !== null && p.push !== undefined" :size="13"
                                    :class="p.push ? 'text-accent' : 'text-ink-faint/40'" :title="p.push ? 'notifications on' : 'notifications off'" />
                                <MapPin :size="13" :class="isLive(p.user_id) ? 'text-accent' : 'text-ink-faint/40'" :title="isLive(p.user_id) ? 'sharing location' : 'location not shared'" />
                            </span>
                        </span>
                    </button>

                    <!-- colour picker (operators) — sets the agent's beacon/route/ring colour; popover teleports to <body> -->
                    <button v-if="c.manage" type="button" @click.stop="toggleColor(p, $event)"
                        class="shrink-0 w-5 h-5 rounded-full border flex items-center justify-center hover:opacity-80"
                        :style="p.color ? { backgroundColor: p.color, borderColor: p.color } : {}" :class="p.color ? '' : 'border-line text-ink-faint'"
                        :title="p.color ? 'change agent colour' : 'set agent colour'"><Palette v-if="!p.color" :size="11" /></button>

                    <!-- route on map (dashboard only, when the agent visits 2+ portals) -->
                    <button v-if="c.focus !== 'roster' && kits[p.user_id].routable" type="button" @click.stop="c.showRoute(p.user_id)"
                        class="shrink-0 w-6 h-6 flex items-center justify-center rounded border"
                        :class="c.routeAgent.value === p.user_id ? 'border-accent text-accent bg-emerald-500/10' : 'border-line text-ink-dim hover:text-accent hover:border-accent/40'"
                        :title="c.routeAgent.value === p.user_id ? 'hide route on map' : 'show route on map'"><Route :size="13" /></button>

                    <!-- account type (role) + faction -->
                    <span class="text-[10px] font-mono uppercase border rounded px-1.5 py-0.5 shrink-0" :class="p.role === 'operative' ? 'border-emerald-500/40 text-accent' : 'border-line text-ink-dim'">{{ isOwnerP(p) ? 'Owner' : roleLabel(p.role) }}</span>
                    <span :class="factionText(p.faction)" class="text-xs font-mono shrink-0">[{{ p.faction || '—' }}]</span>
                </div>

                <!-- expanded: full agent info -->
                <div v-if="openId === p.user_id" class="border-t border-line px-2 py-2 space-y-2.5 text-sm">
                    <div class="flex gap-3">
                        <div class="flex-1 min-w-0 space-y-2.5">
                            <div v-if="c.manage && (p.joined || p.ops_count != null)" class="text-[11px] font-mono text-ink-dim space-y-0.5">
                                <div v-if="p.joined" class="flex items-center gap-1.5"><UserPlus :size="12" class="shrink-0" /> joined · {{ joinedAt(p.joined) }}</div>
                                <div v-if="p.ops_count != null" class="flex items-center gap-1.5"><Boxes :size="12" class="shrink-0" /> on {{ p.ops_count }} op{{ p.ops_count === 1 ? '' : 's' }} total</div>
                            </div>

                            <div class="space-y-1.5">
                                <Link v-if="p.user_id !== c.me" :href="`/ops/${c.data.op.id}?view=dms&dm=${p.user_id}`" class="flex items-center gap-2 text-accent hover:underline"><MessagesSquare :size="15" class="shrink-0" />message in comms</Link>
                                <a v-if="p.phone" :href="`tel:${p.phone}`" class="flex items-center gap-2 text-ink-dim hover:text-accent"><Phone :size="15" class="shrink-0" />{{ p.phone }}</a>
                                <a v-if="p.phone" :href="`sms:${p.phone}`" class="flex items-center gap-2 text-ink-dim hover:text-accent"><MessageSquare :size="15" class="shrink-0" />text message</a>
                                <a v-if="p.telegram" :href="`https://t.me/${p.telegram.replace(/^@/, '')}`" target="_blank" class="flex items-center gap-2 text-ink-dim hover:text-accent"><Send :size="15" class="shrink-0" />{{ p.telegram }}</a>
                                <div v-if="p.preferred" class="flex items-baseline gap-1.5 text-xs"><span class="font-mono uppercase tracking-wide text-[10px] text-ink-faint shrink-0">prefers</span><span class="text-ink">{{ p.preferred }}</span></div>
                                <p v-if="!p.phone && !p.telegram" class="text-xs text-ink-dim">No contact info shared.</p>
                            </div>
                        </div>

                        <!-- full square of the agent's uploaded photo -->
                        <a v-if="p.avatar" :href="p.avatar" target="_blank" rel="noopener" class="shrink-0 w-2/5 max-w-[150px] aspect-square" title="view full photo">
                            <img :src="p.avatar" :alt="p.callsign" class="w-full h-full rounded-lg object-cover border border-line" />
                        </a>
                    </div>

                    <!-- loadout: what this agent needs to bring for their directives (L8 + solo minimal kit) -->
                    <div v-if="!kits[p.user_id].empty" class="pt-2 border-t border-line/60">
                        <div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1.5">Required Loadout</div>
                        <!-- compact label → items rows -->
                        <div class="grid grid-cols-[2.6rem_1fr] gap-x-2 gap-y-1.5 text-xs font-mono items-baseline">
                            <template v-if="kits[p.user_id].resoPortals">
                                <span class="text-[10px] uppercase text-ink-faint pt-0.5">res</span>
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="r in kits[p.user_id].resos" :key="r.level" :style="{ color: RESO_COLORS[r.level], borderColor: RESO_COLORS[r.level] }"
                                        class="inline-flex items-center rounded border px-1 py-0.5 text-[10px] leading-none" :title="`level ${r.level} resonators`">L{{ r.level }}<span class="opacity-60 ml-0.5">×{{ r.n }}</span></span>
                                    <span class="text-ink-faint self-center ml-0.5">= {{ kits[p.user_id].resoTotal }}</span>
                                </div>
                            </template>
                            <template v-if="kits[p.user_id].mods.length">
                                <span class="text-[10px] uppercase text-ink-faint pt-0.5">mods</span>
                                <div class="flex flex-wrap gap-x-2 gap-y-0.5 text-ink">
                                    <span v-for="m in kits[p.user_id].mods" :key="m.name" class="whitespace-nowrap">{{ m.name }} <span class="text-ink-faint">×{{ m.count }}</span></span>
                                </div>
                            </template>
                            <template v-if="kits[p.user_id].viri.length">
                                <span class="text-[10px] uppercase text-ink-faint pt-0.5">viri</span>
                                <div class="flex flex-wrap gap-x-2 gap-y-0.5 text-ink">
                                    <span v-for="v in kits[p.user_id].viri" :key="v.name" class="whitespace-nowrap">{{ v.name }} <span class="text-ink-faint">×{{ v.count }}</span></span>
                                </div>
                            </template>
                            <template v-if="kits[p.user_id].keys.length">
                                <span class="text-[10px] uppercase text-ink-faint pt-0.5">keys</span>
                                <div class="space-y-0.5 text-ink min-w-0">
                                    <div v-for="ky in kits[p.user_id].keys" :key="ky.id" class="flex items-center gap-1.5 min-w-0" :title="`holds ${ky.held} of ${ky.count} key${ky.count === 1 ? '' : 's'}`">
                                        <component :is="ky.have ? Check : X" :size="13" class="shrink-0" :class="ky.have ? 'text-emerald-400' : 'text-rose-400'" />
                                        <span class="shrink-0" :class="ky.have ? 'text-ink' : 'text-rose-300'">×{{ ky.count }}</span>
                                        <span class="text-ink-faint truncate">{{ ky.name }}</span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- role management — agents: any operator; operators: owner only; the owner: no one -->
                    <div v-if="c.manage && !isOwnerP(p) && (p.role !== 'operative' || iAmOwner)" class="pt-2 border-t border-line/60 flex flex-wrap gap-2">
                        <button v-if="p.role !== 'operative'" @click="c.promoteAgent(p)" class="inline-flex items-center gap-1 text-xs font-mono border border-emerald-500/40 rounded px-1.5 py-1.5 text-accent hover:bg-emerald-500/10"><ArrowUpCircle :size="13" /> make Operator</button>
                        <button v-else @click="c.demoteAgent(p)" class="inline-flex items-center gap-1 text-xs font-mono border border-line rounded px-1.5 py-1.5 text-ink-dim hover:text-accent hover:border-accent/40"><ArrowDownCircle :size="13" /> make Agent</button>
                        <button @click="c.kickAgent(p)" class="inline-flex items-center gap-1 text-xs font-mono border border-line rounded px-1.5 py-1.5 text-ink-dim hover:text-amber-300 hover:border-amber-400/40"><UserMinus :size="13" /> kick</button>
                        <button @click="c.banAgent(p)" class="inline-flex items-center gap-1 text-xs font-mono border border-rose-500/30 rounded px-1.5 py-1.5 text-rose-300/80 hover:text-rose-300 hover:bg-rose-500/10"><Ban :size="13" /> ban</button>
                    </div>
                </div>
            </div>
            <p v-if="!c.data.participants.length" class="text-center text-ink-faint text-xs py-4">No agents yet.</p>
        </div>

        <!-- banned agents (operators only) — lift a ban so they can rejoin the op -->
        <div v-if="c.manage && c.data.banned && c.data.banned.length" class="px-1.5 pb-3">
            <div class="flex items-center gap-1.5 text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1.5"><Ban :size="12" /> Banned</div>
            <div class="space-y-1">
                <div v-for="b in c.data.banned" :key="b.user_id" class="flex items-center gap-2 border border-line/60 rounded-lg bg-surface px-2 py-1.5">
                    <span class="min-w-0 flex-1 truncate">
                        <span class="text-sm text-ink">{{ b.callsign }}</span>
                        <span :class="factionText(b.faction)" class="text-xs font-mono ml-1">[{{ b.faction || '—' }}]</span>
                    </span>
                    <span v-if="b.banned_by" class="hidden sm:inline text-[10px] font-mono text-ink-faint shrink-0" :title="b.at ? joinedAt(b.at) : ''">by {{ b.banned_by }}</span>
                    <button @click="c.unbanAgent(b)" class="shrink-0 inline-flex items-center gap-1 text-xs font-mono border border-emerald-500/40 rounded px-1.5 py-1.5 text-accent hover:bg-emerald-500/10" title="lift the ban — they can rejoin"><RotateCcw :size="13" /> unban</button>
                </div>
            </div>
        </div>

        <!-- colour popover: teleported to <body> + fixed so the card's overflow-hidden never clips it -->
        <Teleport to="body">
            <template v-if="colorParticipant">
                <div @click="closeColor" class="fixed inset-0 z-[60]"></div>
                <div class="fixed z-[61] w-40 bg-surface border border-line rounded-lg shadow-xl p-2"
                    :style="{ top: colorPop.top + 'px', left: colorPop.left + 'px', transform: 'translateX(-100%)' }">
                    <div class="grid grid-cols-6 gap-1">
                        <button v-for="col in AGENT_COLORS" :key="col" type="button" @click="pickColor(colorParticipant, col)" :title="col"
                            class="w-5 h-5 rounded-full border hover:scale-110 transition-transform" :style="{ backgroundColor: col, borderColor: col }"
                            :class="colorParticipant.color === col ? 'ring-2 ring-white ring-offset-1 ring-offset-surface' : ''"></button>
                    </div>
                    <button type="button" @click="pickColor(colorParticipant, null)" class="mt-1.5 w-full text-[10px] font-mono uppercase text-ink-dim hover:text-ink border border-line rounded px-1 py-1">clear</button>
                </div>
            </template>
        </Teleport>
    </div>
</template>
