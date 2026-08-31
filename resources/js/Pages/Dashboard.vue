<script setup>
import { ref, watch, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import { Import, GripVertical, LayoutGrid, Inbox } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { roleLabel } from '@/faction';
import { mapTheme } from '@/maps';
import { lsGet } from '@/ls';

const props = defineProps({ ops: { type: Array, default: () => [] } });

const creating = ref(false);
function newOp() {
    creating.value = true;
    router.post('/ops', {}, { onFinish: () => (creating.value = false) });
}

const joinLink = ref('');
function join() {
    const m = joinLink.value.trim().match(/\/j\/([A-Za-z0-9]+)/) || [null, joinLink.value.trim()];
    if (m[1]) router.visit(`/j/${m[1]}`);
}

const importForm = useForm({ file: null });
function onImport(e) {
    const f = e.target.files[0];
    if (!f) return;
    importForm.file = f;
    importForm.post('/ops/import', { onFinish: () => (e.target.value = '') });
}

const statusColor = {
    planning: 'text-ink-dim border-line',
    upcoming: 'text-amber-300 border-amber-500/40',
    active: 'text-accent border-emerald-500/40',
    complete: 'text-sky-300 border-sky-400/40',
    closed: 'text-ink-faint border-line',
};
// agents see a planning op they've been added to as "upcoming"; operatives still see it as "planning"
function opStatus(op) { return !op.is_operative && op.status === 'planning' ? 'upcoming' : op.status; }
function fmtCreated(iso) { try { return new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return ''; } }

// Drag-to-reorder the op cards (vuedraggable, same as the directives panel). The order is per-user and
// saved to the server so it sticks across reloads; localOps is our editable copy of the ops prop.
const localOps = ref([...props.ops]);
watch(() => props.ops, (v) => { localOps.value = [...v]; });
function saveOrder() {
    window.axios.put('/dashboard/order', { order: localOps.value.map((o) => o.id) }).catch(() => {});
}

// Static map thumbnail per op: a lazy ArcGIS export image framed to the op's waypoints, with the dots
// projected on top. It mirrors the op's saved satellite/street choice + the day/night theme, but never
// mounts a live map (which is WebGL and would blow the browser's context limit across many cards).
const ARCGIS = 'https://server.arcgisonline.com/ArcGIS/rest/services';
function thumbService(opId, theme) {
    const sat = lsGet(`toady-map-sat:${opId}`); // same per-op key OpMap writes: '1' | 'sat' | 'hybrid' | 'off'
    if (['1', 'sat', 'hybrid'].includes(sat)) return 'World_Imagery';
    return theme === 'day' ? 'Canvas/World_Light_Gray_Base' : 'Canvas/World_Dark_Gray_Base';
}
function buildThumb(op, theme) {
    const pts = op.wps || [];
    if (!pts.length) return null; // no placed waypoints → no thumbnail (the card stays text-only)
    let s = 90, n = -90, w = 180, e = -180;
    for (const [lat, lng] of pts) { s = Math.min(s, lat); n = Math.max(n, lat); w = Math.min(w, lng); e = Math.max(e, lng); }
    const cLat = (s + n) / 2, cLng = (w + e) / 2, MIN = 0.003, ASPECT = 2; // pad + enforce a min span; 2:1 banner
    let latSpan = Math.max(n - s, MIN) * 1.3, lngSpan = Math.max(e - w, MIN) * 1.3;
    if (lngSpan / latSpan < ASPECT) lngSpan = latSpan * ASPECT; else latSpan = lngSpan / ASPECT;
    const W = cLng - lngSpan / 2, E = cLng + lngSpan / 2, S = cLat - latSpan / 2, N = cLat + latSpan / 2;
    const url = `${ARCGIS}/${thumbService(op.id, theme)}/MapServer/export?bbox=${W},${S},${E},${N}&bboxSR=4326&size=480,240&format=jpg&f=image`;
    // the export bbox is plate-carrée (bboxSR=4326), so dots project as a straight linear fraction of the box
    const dots = pts.map(([lat, lng]) => ({ left: ((lng - W) / (E - W)) * 100, top: ((N - lat) / (N - S)) * 100 }));
    return { url, dots };
}
const thumbs = computed(() => {
    const theme = mapTheme();
    return Object.fromEntries(localOps.value.map((op) => [op.id, buildThumb(op, theme)]));
});
</script>

<template>
    <Head title="Ops" />
    <AppLayout>
        <template #title><span class="inline-flex items-center gap-1.5 font-mono text-accent glow tracking-wide"><LayoutGrid :size="18" /> Operations</span></template>

        <!-- controls, one row: new op · paste-a-join-link · import a saved plan -->
        <form @submit.prevent="join" class="flex gap-2 mb-6">
            <button type="button" @click="newOp" :disabled="creating" class="shrink-0 text-sm font-mono bg-accent hover:bg-emerald-400 text-accent-ink font-semibold rounded px-2 py-1.5 disabled:opacity-50">+ new op</button>
            <input v-model="joinLink" placeholder="paste an op join link to join…"
                class="flex-1 min-w-0 bg-inset border border-line rounded px-1.5 py-1.5 text-sm font-mono focus:border-accent focus:outline-none" />
            <button type="submit" class="shrink-0 bg-emerald-500/20 hover:bg-emerald-500/30 text-accent font-mono text-sm rounded px-2 py-1.5">join</button>
            <label title="import a saved plan" class="shrink-0 flex items-center justify-center border border-line text-ink-dim hover:text-accent hover:border-accent/40 rounded px-2 cursor-pointer">
                <input type="file" accept="application/json,.json" class="hidden" @change="onImport" />
                <Import :size="16" />
            </label>
        </form>

        <div v-if="localOps.length === 0" class="border border-dashed border-line rounded-lg px-2 py-10 text-center">
            <p class="text-ink-dim text-sm">No active ops.</p>
            <p class="text-ink-faint text-xs mt-1 font-mono">Hit “+ new op” to start one, or paste a join link.</p>
        </div>

        <!-- drag-to-reorder cards (vuedraggable, like the directives panel); order saves per-user -->
        <draggable v-else v-model="localOps" item-key="id" tag="div" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            handle=".op-handle" :disabled="localOps.length < 2" :touchStartThreshold="8" :animation="160" ghost-class="op-ghost" @end="saveOrder">
            <template #item="{ element: op }">
                <div class="op-card relative border border-line rounded-lg bg-surface hover:border-emerald-500/40 transition-colors overflow-hidden">
                    <Link :href="`/ops/${op.id}`" class="block">
                        <!-- unread-notifications counter, top-left (same Inbox icon as the header bell) -->
                        <span v-if="op.unread" class="absolute top-1.5 left-1.5 z-10 inline-flex items-center gap-1 rounded bg-accent text-accent-ink text-[10px] font-mono px-1.5 py-0.5 shadow" :title="`${op.unread} unread notification${op.unread === 1 ? '' : 's'}`"><Inbox :size="12" /> {{ op.unread > 9 ? '9+' : op.unread }}</span>
                        <!-- static map thumbnail (matches the op's satellite/street + day/night); dots = placed waypoints -->
                        <div v-if="thumbs[op.id]" class="relative border-b border-line/60">
                            <img :src="thumbs[op.id].url" loading="lazy" alt="" style="aspect-ratio: 2 / 1" class="block w-full object-cover bg-inset" />
                            <span v-for="(d, i) in thumbs[op.id].dots" :key="i" class="absolute w-1.5 h-1.5 rounded-full bg-accent shadow-[0_0_0_1.5px_rgba(0,0,0,.6)]" :style="{ left: d.left + '%', top: d.top + '%', transform: 'translate(-50%, -50%)' }"></span>
                        </div>
                        <div class="px-1.5 py-4">
                            <div class="pr-7 font-medium text-ink">{{ op.name }}</div>
                            <div class="mt-2 text-xs font-mono text-ink-faint flex items-center gap-2">
                                <span class="text-accent/80">{{ roleLabel(op.is_operative ? 'operative' : 'agent') }}</span>
                                <span>· {{ op.participants }} agent{{ op.participants === 1 ? '' : 's' }}</span>
                            </div>
                            <div v-if="op.created" class="mt-1 pr-16 text-[10px] font-mono text-ink-faint">created {{ fmtCreated(op.created) }}</div>
                        </div>
                        <!-- status badge, pinned to the card's bottom-right corner (mirrors the top-right drag grip) -->
                        <span class="absolute bottom-1.5 right-1.5 z-10 text-[10px] font-mono uppercase border rounded px-1.5 py-0.5" :class="statusColor[opStatus(op)]">{{ opStatus(op) }}</span>
                    </Link>
                    <button v-if="localOps.length > 1" type="button" class="op-handle absolute top-1.5 right-1.5 z-10 flex items-center justify-center w-6 h-6 rounded bg-surface/80 backdrop-blur-sm text-ink-faint hover:text-accent cursor-grab active:cursor-grabbing touch-none" title="drag to reorder" aria-label="drag to reorder">
                        <GripVertical :size="14" />
                    </button>
                </div>
            </template>
        </draggable>
    </AppLayout>
</template>
