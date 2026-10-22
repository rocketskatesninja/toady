<script setup>
import { inject, computed } from 'vue';
import { UserPlus, CheckCircle2, KeyRound } from 'lucide-vue-next';
import { relTime } from '@/time';

// Operator-only activity feed. Derived entirely from records the op already stores with timestamps —
// no separate event log to maintain. Surfaces agents joining, directives completed, and key reports
// (newest first). Op-setup actions aren't logged, so they never show up here.
const c = inject('opctx');

const callsign = (id) => c.data.participants.find((p) => p.user_id === id)?.callsign || 'an agent';
const portal = (id) => c.data.waypoints.find((w) => w.id === id)?.title || 'a portal';

const events = computed(() => {
    const out = [];

    for (const p of c.data.participants) {
        if (p.joined) out.push({ id: 'j' + p.id, at: p.joined, icon: UserPlus, color: 'text-sky-300', who: p.callsign, what: `joined as ${p.role}` });
    }
    for (const s of c.data.steps) {
        if (s.done && s.done_at) {
            const label = s.text || (s.action ? `a ${s.action}` : 'a directive');
            out.push({ id: 'd' + s.id, at: s.done_at, icon: CheckCircle2, color: 'text-accent', who: callsign(s.done_by), what: `completed ${label} at ${portal(s.op_waypoint_id)}` });
        }
    }
    for (const h of c.data.keyHoldings) {
        if (h.updated_at && h.qty > 0) out.push({ id: 'k' + h.op_waypoint_id + '-' + h.user_id, at: h.updated_at, icon: KeyRound, color: 'text-amber-300', who: h.callsign || 'an agent', what: `reported ${h.qty} key${h.qty === 1 ? '' : 's'} for ${portal(h.op_waypoint_id)}` });
    }

    return out.sort((a, b) => new Date(b.at) - new Date(a.at)).slice(0, 200); // newest first, capped
});

</script>

<template>
    <div v-if="c.manage" class="px-2 py-2">
        <p v-if="!events.length" class="text-center text-ink-faint text-xs py-6 leading-relaxed">
            Quiet so far.<br>Joins, completed directives, and key reports stream in here.
        </p>
        <ul v-else class="space-y-0">
            <li v-for="e in events" :key="e.id" class="flex items-start gap-2 text-xs py-1.5 border-b border-line/30 last:border-0">
                <component :is="e.icon" :size="14" class="shrink-0 mt-0.5" :class="e.color" />
                <span class="min-w-0 flex-1 leading-snug"><span class="font-mono text-ink">{{ e.who }}</span> <span class="text-ink-dim">{{ e.what }}</span></span>
                <span class="shrink-0 font-mono text-[10px] text-ink-faint tabular-nums" :title="e.at">{{ relTime(e.at) }}</span>
            </li>
        </ul>
    </div>
    <p v-else class="text-center text-ink-faint text-xs py-6">The activity log is for operators.</p>
</template>
