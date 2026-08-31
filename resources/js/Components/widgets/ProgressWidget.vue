<script setup>
import { inject, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Award, CheckCircle2 } from 'lucide-vue-next';
import { analyzeFields } from '@/fields';
import { relTime } from '@/time';

const c = inject('opctx');
const page = usePage();
const me = c.me;

const steps = computed(() => c.data.steps || []);
// completed/planned fields + AP (exact-ish) and a rough MU estimate
const fld = computed(() => analyzeFields(c.data.steps || [], c.data.waypoints || [], page.props.mu_density));
const total = computed(() => steps.value.length);
const done = computed(() => steps.value.filter((s) => s.done).length);
const pct = computed(() => (total.value ? Math.round((done.value / total.value) * 100) : 0));

// a location is "cleared" once every directive on it is checked off
const placed = computed(() => c.data.waypoints.filter((w) => steps.value.some((s) => s.op_waypoint_id === w.id)));
const clearedLoc = computed(() => placed.value.filter((w) => {
    const t = steps.value.filter((s) => s.op_waypoint_id === w.id);
    return t.length && t.every((s) => s.done);
}).length);

// total keys still to farm across the plan
const keysShort = computed(() => c.data.waypoints.reduce((sum, w) => {
    const need = w.keys_needed || 0;
    if (!need) return sum;
    const held = (c.data.keyHoldings || []).filter((h) => h.op_waypoint_id === w.id).reduce((s, h) => s + h.qty, 0);
    return sum + Math.max(0, need - held);
}, 0));

const onMap = computed(() => (c.data.presence || []).filter((p) => p.lat != null).length);
const totalAgents = computed(() => (c.data.participants || []).length);
// the after-action report is grabbable any time the op is live or done and something's been cleared —
// not only at a clean 100% — so the tally can be saved before the op is closed and purged.
const reportable = computed(() => done.value > 0 && c.data.op.status !== 'planning');

// --- helpers ---
const callsignOf = (uid) => (c.data.participants || []).find((p) => p.user_id === uid)?.callsign || '—';
const stepLabel = (s) => s.text || (s.action ? s.action.charAt(0).toUpperCase() + s.action.slice(1) : 'Directive');
const wpTitle = (s) => (c.data.waypoints || []).find((w) => w.id === s.op_waypoint_id)?.title || '';
const ago = (iso) => relTime(iso, { suffix: true });

// recent completions — newest first
const recent = computed(() => steps.value
    .filter((s) => s.done && s.done_at)
    .sort((a, b) => new Date(b.done_at) - new Date(a.done_at))
    .slice(0, 4));

// per-agent contributions — done directives grouped by who checked them off
const contributions = computed(() => {
    const m = {};
    steps.value.forEach((s) => { if (s.done && s.done_by) m[s.done_by] = (m[s.done_by] || 0) + 1; });
    return Object.entries(m).map(([uid, n]) => ({ callsign: callsignOf(+uid), n })).sort((a, b) => b.n - a.n).slice(0, 5);
});

// your work + unclaimed
const mine = computed(() => steps.value.filter((s) => s.assignee_id === me));
const mineDone = computed(() => mine.value.filter((s) => s.done).length);
const unclaimed = computed(() => steps.value.filter((s) => !s.done && !s.assignee_id).length);

// pace — time since the last completion; flag a long lull
const lastDoneAt = computed(() => recent.value[0]?.done_at || null);
const stalled = computed(() => lastDoneAt.value && total.value && done.value < total.value
    && c.data.op.status === 'active' && (Date.now() - new Date(lastDoneAt.value).getTime()) > 15 * 60 * 1000);

// sequential front — for ordered ops, the first location with an open directive
const sequential = computed(() => ['visible', 'hidden'].includes(c.data.op.type));
const frontIdx = computed(() => placed.value.findIndex((w) => steps.value.some((s) => s.op_waypoint_id === w.id && !s.done)));

// shared card styling for the stat sections so they read as distinct blocks (esp. side-by-side, full page)
const sec = 'rounded-lg border border-line/60 bg-inset/30 p-2.5';
</script>

<template>
    <div class="@container px-2.5 py-3 flex flex-col gap-3">
        <!-- headline % + bar -->
        <div>
            <div class="flex items-end justify-between mb-1">
                <span class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Op progress</span>
                <span class="font-mono text-accent glow text-2xl leading-none">{{ pct }}%</span>
            </div>
            <div class="h-2.5 rounded-full bg-inset border border-line overflow-hidden">
                <div class="h-full bg-accent rounded-full transition-[width] duration-500" :style="{ width: pct + '%' }"></div>
            </div>
        </div>

        <!-- stat sections: one column on a small card; flow into 2–3 columns when the panel is wide (full page) -->
        <div class="grid gap-x-4 gap-y-3 items-start @lg:grid-cols-2 @3xl:grid-cols-3">
        <!-- objectives: directive + location completion -->
        <div :class="sec">
            <p class="text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">Objectives</p>
            <ul class="text-sm space-y-1.5 font-mono">
                <li class="flex items-center justify-between gap-2"><span class="text-ink-dim">Directives</span><span class="text-ink">{{ done }}/{{ total }}</span></li>
                <li class="flex items-center justify-between gap-2"><span class="text-ink-dim">Locations cleared</span><span class="text-ink">{{ clearedLoc }}/{{ placed.length }}</span></li>
                <li v-if="mine.length" class="flex items-center justify-between gap-2"><span class="text-ink-dim">Your directives</span><span :class="mineDone === mine.length ? 'text-accent' : 'text-ink'">{{ mineDone }}/{{ mine.length }}</span></li>
                <li v-if="unclaimed" class="flex items-center justify-between gap-2"><span class="text-ink-dim">Unclaimed</span><span class="text-amber-300">{{ unclaimed }} open</span></li>
                <li v-if="sequential && frontIdx >= 0" class="flex items-center justify-between gap-2"><span class="text-ink-dim">Front</span><span class="text-ink">waypoint {{ frontIdx + 1 }}/{{ placed.length }}</span></li>
            </ul>
        </div>

        <!-- fielding: keys, links, fields + score -->
        <div :class="sec">
            <p class="text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">Fielding</p>
            <ul class="text-sm space-y-1.5 font-mono">
                <li class="flex items-center justify-between gap-2"><span class="text-ink-dim">Keys</span><span :class="keysShort ? 'text-rose-400' : 'text-accent'">{{ keysShort ? keysShort + ' to farm' : 'all keyed' }}</span></li>
                <li v-if="fld.linksPlanned" class="flex items-center justify-between gap-2"><span class="text-ink-dim">Links</span><span :class="fld.linksDone === fld.linksPlanned ? 'text-accent' : 'text-ink'">{{ fld.linksDone }}/{{ fld.linksPlanned }}</span></li>
                <li v-if="fld.plannedCount" class="flex items-center justify-between gap-2"><span class="text-ink-dim">Fields</span><span :class="fld.completedCount === fld.plannedCount ? 'text-accent' : 'text-ink'">{{ fld.completedCount }}/{{ fld.plannedCount }}</span></li>
                <li v-if="fld.ap" class="flex items-center justify-between gap-2"><span class="text-ink-dim">Est. AP</span><span class="text-accent">{{ fld.ap.toLocaleString() }}</span></li>
                <li v-if="fld.mu" class="flex items-center justify-between gap-2"><span class="text-ink-dim" title="Rough — true MU depends on the population inside the field area">Est. MU</span><span class="text-ink">~{{ fld.mu.toLocaleString() }}</span></li>
            </ul>
        </div>

        <!-- team: presence + pace -->
        <div :class="sec">
            <p class="text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">Team</p>
            <ul class="text-sm space-y-1.5 font-mono">
                <li class="flex items-center justify-between gap-2"><span class="text-ink-dim">Agents on map</span><span class="text-ink">{{ onMap }}/{{ totalAgents }}</span></li>
                <li v-if="lastDoneAt" class="flex items-center justify-between gap-2"><span class="text-ink-dim">Last activity</span><span :class="stalled ? 'text-amber-300' : 'text-ink'">{{ ago(lastDoneAt) }}<template v-if="stalled"> · quiet</template></span></li>
            </ul>
        </div>

        <!-- recent completions -->
        <div v-if="recent.length" :class="sec">
            <p class="text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">Recent</p>
            <ul class="space-y-1">
                <li v-for="s in recent" :key="s.id" class="flex items-start gap-1.5">
                    <CheckCircle2 :size="12" class="shrink-0 mt-0.5 text-accent/70" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-xs text-ink">{{ stepLabel(s) }}</span>
                            <span class="shrink-0 text-[10px] font-mono text-ink-faint">{{ ago(s.done_at) }}</span>
                        </div>
                        <div class="truncate text-[10px] font-mono text-ink-faint"><span v-if="wpTitle(s)" class="text-ink-dim">{{ wpTitle(s) }}</span><template v-if="wpTitle(s)"> · </template>@{{ callsignOf(s.done_by) }}</div>
                    </div>
                </li>
            </ul>
        </div>

        <!-- per-agent contributions -->
        <div v-if="contributions.length" :class="sec">
            <p class="text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">By agent</p>
            <ul class="space-y-1 font-mono text-xs">
                <li v-for="ct in contributions" :key="ct.callsign" class="flex items-center justify-between gap-2">
                    <span class="text-ink-dim truncate">{{ ct.callsign }}</span>
                    <span class="text-ink">{{ ct.n }} done</span>
                </li>
            </ul>
        </div>
        </div>

        <p v-if="!total" class="text-ink-faint text-xs text-center mt-1">No directives yet — add some to track progress.</p>

        <!-- open the after-action report (save it before closing the op purges everything) -->
        <button v-if="reportable" @click="c.openReport()"
            class="flex items-center justify-center gap-1.5 bg-accent/15 hover:bg-accent/25 border border-accent/40 text-accent font-mono text-xs rounded px-2 py-2 transition-colors">
            <Award :size="14" /> After-action report
        </button>
    </div>
</template>
