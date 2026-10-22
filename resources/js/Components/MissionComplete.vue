<script setup>
import { inject, ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Download, Copy, Check, Share2 } from 'lucide-vue-next';
import { factionText, roleLabel } from '@/faction';
import { analyzeFields } from '@/fields';
import Avatar from '@/Components/Avatar.vue';

const c = inject('opctx');
const emit = defineEmits(['close']);

const op = computed(() => c.data.op);
const steps = computed(() => c.data.steps || []);
const waypoints = computed(() => c.data.waypoints || []);
const participants = computed(() => c.data.participants || []);

const cleared = computed(() => steps.value.filter((s) => s.done).length);
const total = computed(() => steps.value.length);
// the report opens at 100% (a win) but is also grabbable mid-op before a close purges it — so it reads
// as a full "mission complete" only when everything's cleared, else an interim after-action snapshot.
const allDone = computed(() => total.value > 0 && cleared.value === total.value);

// run time = first directive cleared → last (the active execution window)
const stamps = computed(() => steps.value.map((s) => s.done_at).filter(Boolean).map((t) => new Date(t).getTime()));
const runMs = computed(() => (stamps.value.length ? Math.max(...stamps.value) - Math.min(...stamps.value) : 0));
const completedAt = computed(() => (stamps.value.length ? new Date(Math.max(...stamps.value)) : null));
function fmtRunTime(ms) {
    const m = Math.round(ms / 60000);
    if (m < 1) return '<1m';
    if (m < 60) return `${m}m`;
    return `${Math.floor(m / 60)}h ${m % 60}m`;
}

// links, fields, AP (incl. field bonuses) + a rough MU — from the shared analyzer, so the report
// matches the Progress widget + the map shading exactly.
const page = usePage();
const fld = computed(() => analyzeFields(steps.value, waypoints.value, page.props.mu_density));

// completed directives grouped by action type
const actionCounts = computed(() => {
    const m = {};
    steps.value.forEach((s) => { if (s.done && s.action) m[s.action] = (m[s.action] || 0) + 1; });
    return Object.entries(m).sort((a, b) => b[1] - a[1]);
});

// the full roster, each with their contribution + estimated AP, best first
const clearedBy = computed(() => {
    const m = {};
    steps.value.forEach((s) => { if (s.done && s.done_by) m[s.done_by] = (m[s.done_by] || 0) + 1; });
    return m;
});
const agents = computed(() => participants.value
    .map((p) => ({ ...p, cleared: clearedBy.value[p.user_id] || 0 }))
    .sort((a, b) => b.cleared - a.cleared));

const totalKeys = computed(() => (c.data.keyHoldings || []).reduce((s, h) => s + (h.qty || 0), 0));

// plain-text debrief — emoji + clean lines paste cleanly into Telegram / Discord / SMS
function reportText() {
    const L = allDone.value
        ? [`🎯 MISSION COMPLETE — ${op.value.name}`, 'all directives cleared']
        : [`🎯 AFTER-ACTION — ${op.value.name}`, `${cleared.value}/${total.value} directives cleared`];
    if (completedAt.value) L.push(completedAt.value.toLocaleString());
    L.push('', `⏱ Run time: ${fmtRunTime(runMs.value)}`, `✅ Directives: ${cleared.value}/${total.value}`, `📍 Waypoints: ${waypoints.value.length}`, `⚡ Est. AP: ${fld.value.ap.toLocaleString()}`);
    if (fld.value.linksPlanned) L.push(`🔗 Links: ${fld.value.linksDone}/${fld.value.linksPlanned}`);
    if (fld.value.plannedCount) L.push(`🔺 Fields: ${fld.value.completedCount}/${fld.value.plannedCount}`);
    if (fld.value.mu) L.push(`🧠 Est. MU: ~${fld.value.mu.toLocaleString()}`);
    if (totalKeys.value) L.push(`🔑 Keys staged: ${totalKeys.value}`);
    if (actionCounts.value.length) L.push(`🎬 Actions: ${actionCounts.value.map(([a, n]) => `${a} ×${n}`).join(' · ')}`);
    L.push('', `👥 Agents (${participants.value.length}):`);
    agents.value.forEach((a) => L.push(`• ${a.callsign} [${a.faction || '—'}] · ${roleLabel(a.role)}${a.cleared ? ` — ${a.cleared} cleared` : ''}`));
    L.push('', 'via toady.net');
    return L.join('\n');
}

const copied = ref(false);
async function copyReport() {
    try {
        await navigator.clipboard.writeText(reportText());
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1800);
    } catch (e) { /* clipboard blocked */ }
}
const canShare = typeof navigator !== 'undefined' && !!navigator.share;
async function shareReport() {
    try { await navigator.share({ title: `${op.value.name} — after action`, text: reportText() }); } catch (e) { /* cancelled */ }
}
const slug = () => (op.value.name || 'op').replace(/\W+/g, '_');
function download(blob, ext) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `after-action-${slug()}.${ext}`;
    a.click();
    URL.revokeObjectURL(url);
}
function exportReport() {
    download(new Blob([reportText()], { type: 'text/plain' }), 'txt');
}
// structured record for the team's own archive — the op itself is purged on close, so this is the keepable copy
function exportJson() {
    const data = {
        op: { name: op.value.name, type: op.value.type },
        completed_at: completedAt.value?.toISOString() || null,
        run_seconds: Math.round(runMs.value / 1000),
        directives: { cleared: cleared.value, total: total.value },
        waypoints: waypoints.value.length,
        est_ap: fld.value.ap,
        est_mu: fld.value.mu,
        links: { done: fld.value.linksDone, planned: fld.value.linksPlanned },
        fields: { completed: fld.value.completedCount, planned: fld.value.plannedCount },
        area_km2: Math.round(fld.value.areaKm2 * 1000) / 1000,
        keys_staged: totalKeys.value,
        actions: Object.fromEntries(actionCounts.value),
        agents: agents.value.map((a) => ({ callsign: a.callsign, faction: a.faction, role: a.role, cleared: a.cleared })),
        generated_at: new Date().toISOString(),
        via: 'toady.net',
    };
    download(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }), 'json');
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 bg-black/70" @click.self="emit('close')">
        <div class="aar w-full max-w-md max-h-[92vh] sm:max-h-[88vh] overflow-y-auto op-scroll border border-accent rounded-lg bg-surface shadow-2xl">
            <!-- header -->
            <div class="relative px-4 py-4 border-b border-accent/40 bg-accent/10 text-center overflow-hidden">
                <div class="aar-sweep"></div>
                <div class="text-lg sm:text-2xl font-mono font-bold text-accent glow tracking-wide sm:tracking-wider break-words">{{ allDone ? '✓ MISSION COMPLETE' : 'AFTER-ACTION REPORT' }}</div>
                <div class="text-[11px] font-mono text-ink-dim mt-1">{{ op.name }} · {{ allDone ? 'all directives cleared' : `${cleared}/${total} directives cleared` }}</div>
            </div>

            <!-- stat grid -->
            <div class="grid grid-cols-3 gap-px bg-line/40">
                <div class="bg-surface px-2 py-2 sm:px-3 sm:py-2.5"><div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Run time</div><div class="text-base sm:text-lg font-mono text-ink">{{ fmtRunTime(runMs) }}</div></div>
                <div class="bg-surface px-2 py-2 sm:px-3 sm:py-2.5"><div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Directives</div><div class="text-base sm:text-lg font-mono text-ink">{{ cleared }}<span v-if="!allDone" class="text-ink-faint">/{{ total }}</span></div></div>
                <div class="bg-surface px-2 py-2 sm:px-3 sm:py-2.5" title="Estimated from the actions + fields cleared — real AP varies with reso counts"><div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Est. AP</div><div class="text-base sm:text-lg font-mono text-ink">{{ fld.ap.toLocaleString() }}</div></div>
                <div class="bg-surface px-2 py-2 sm:px-3 sm:py-2.5"><div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Waypoints</div><div class="text-base sm:text-lg font-mono text-ink">{{ waypoints.length }}</div></div>
                <div class="bg-surface px-2 py-2 sm:px-3 sm:py-2.5"><div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Agents</div><div class="text-base sm:text-lg font-mono text-ink">{{ participants.length }}</div></div>
                <div class="bg-surface px-2 py-2 sm:px-3 sm:py-2.5"><div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Keys</div><div class="text-base sm:text-lg font-mono text-ink">{{ totalKeys }}</div></div>
                <div v-if="fld.linksPlanned" class="bg-surface px-2 py-2 sm:px-3 sm:py-2.5"><div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Links</div><div class="text-base sm:text-lg font-mono text-ink">{{ fld.linksDone }}/{{ fld.linksPlanned }}</div></div>
                <div v-if="fld.plannedCount" class="bg-surface px-2 py-2 sm:px-3 sm:py-2.5"><div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Fields</div><div class="text-base sm:text-lg font-mono text-ink">{{ fld.completedCount }}/{{ fld.plannedCount }}</div></div>
                <div v-if="fld.mu" class="bg-surface px-2 py-2 sm:px-3 sm:py-2.5" title="Rough — true MU depends on the population inside the field area"><div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">Est. MU</div><div class="text-base sm:text-lg font-mono text-ink">~{{ fld.mu.toLocaleString() }}</div></div>
            </div>

            <!-- action breakdown -->
            <div v-if="actionCounts.length" class="px-3 py-2 border-t border-line flex flex-wrap gap-1.5">
                <span v-for="[a, n] in actionCounts" :key="a" class="text-[10px] font-mono border border-line rounded px-1.5 py-0.5 text-ink-dim">{{ a }} ×{{ n }}</span>
            </div>

            <!-- agent roster with photos -->
            <div class="px-3 py-2.5 border-t border-line">
                <div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-2">Agents · {{ participants.length }}</div>
                <div class="space-y-1.5">
                    <div v-for="a in agents" :key="a.user_id" class="flex items-center gap-2">
                        <Avatar :src="a.avatar" :callsign="a.callsign" :faction="a.faction" :size="26" />
                        <span class="font-mono text-sm truncate" :class="factionText(a.faction)">{{ a.callsign }}</span>
                        <span class="text-[9px] font-mono uppercase border border-line rounded px-1 text-ink-dim shrink-0">{{ roleLabel(a.role) }}</span>
                        <span class="ml-auto font-mono text-xs shrink-0" :class="a.cleared ? 'text-ink-dim' : 'text-ink-faint'">{{ a.cleared }} cleared</span>
                    </div>
                </div>
            </div>

            <!-- actions -->
            <div class="px-3 py-2.5 border-t border-line flex flex-wrap items-center gap-2">
                <button @click="copyReport" title="Copy as text to paste into Telegram / Discord" class="flex items-center gap-1 bg-accent/15 hover:bg-accent/25 border border-accent/40 text-accent font-mono text-sm rounded px-2 py-1.5"><component :is="copied ? Check : Copy" :size="14" /> {{ copied ? 'copied' : 'copy' }}</button>
                <button v-if="canShare" @click="shareReport" class="flex items-center gap-1 text-ink-dim hover:text-accent font-mono text-sm rounded px-2 py-1.5"><Share2 :size="14" /> share</button>
                <button @click="exportReport" title="Download as text" class="flex items-center gap-1 text-ink-dim hover:text-accent font-mono text-sm rounded px-2 py-1.5"><Download :size="14" /> .txt</button>
                <button @click="exportJson" title="Download as JSON for your records" class="flex items-center gap-1 text-ink-dim hover:text-accent font-mono text-sm rounded px-2 py-1.5"><Download :size="14" /> .json</button>
                <button @click="emit('close')" class="ml-auto text-sm font-mono text-ink-faint hover:text-ink px-2 py-1.5">close</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.aar {
    animation: aar-in 0.35s cubic-bezier(0.2, 0.9, 0.3, 1.2);
    box-shadow: 0 0 0 1px var(--color-accent), 0 0 40px -6px color-mix(in srgb, var(--color-accent) 55%, transparent);
}
@keyframes aar-in {
    from { opacity: 0; transform: scale(0.92) translateY(8px); }
    to { opacity: 1; transform: none; }
}
.aar-sweep {
    position: absolute;
    inset: 0;
    background: linear-gradient(100deg, transparent 30%, color-mix(in srgb, var(--color-accent) 35%, transparent) 50%, transparent 70%);
    transform: translateX(-100%);
    animation: aar-sweep 1.1s ease-out 0.2s;
}
@keyframes aar-sweep {
    to { transform: translateX(100%); }
}
@media (prefers-reduced-motion: reduce) { .aar, .aar-sweep { animation: none; } }
</style>
