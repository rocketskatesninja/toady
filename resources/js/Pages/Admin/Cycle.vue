<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';
import { TimerReset } from 'lucide-vue-next';
import { computeCycle, fmtDur } from '@/cycle';

const props = defineProps({
    cycle: { type: Object, default: null }, // { anchor, interval_hours, checkpoints_per_cycle, year, number } or null
    mu_density: { type: Number, default: 375 }, // people/km² for the MU field-scoring estimate
});

const field = 'w-full bg-inset border border-line rounded px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none';

// stored anchor is an absolute ISO instant; the <input type=datetime-local step=1> wants local wall-clock
// "YYYY-MM-DDTHH:MM:SS" — seconds INCLUDED so the checkpoint can be calibrated to the exact second
const pad = (n) => String(n).padStart(2, '0');
function toLocalInput(iso) {
    if (! iso) return '';
    const d = new Date(iso);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

const form = useForm({
    anchor: toLocalInput(props.cycle?.anchor),
    interval_hours: props.cycle?.interval_hours ?? 5,
    checkpoints_per_cycle: props.cycle?.checkpoints_per_cycle ?? 35,
    label: props.cycle?.year && props.cycle?.number ? `${props.cycle.year}.${String(props.cycle.number).padStart(2, '0')}` : '',
});

// "YYYY.NN" → { year, number } for the live preview (server parses + validates the same shape)
function parseLabel(s) {
    const m = /^(\d{4})\.(\d{1,3})$/.exec((s || '').trim());
    return m ? { year: Number(m[1]), number: Number(m[2]) } : {};
}

function save() {
    // send the local datetime as an absolute instant; keep the input bound to its local value
    form.transform((d) => ({ ...d, anchor: d.anchor ? new Date(d.anchor).toISOString() : null }))
        .put('/admin/cycle', { preserveScroll: true });
}

// MU field-scoring density — region-specific, calibrated against a real op
const muForm = useForm({ density: props.mu_density });
function saveMu() { muForm.put('/admin/cycle/mu', { preserveScroll: true }); }

// live preview — mirrors exactly what the widget will show, from the values currently in the form
const now = ref(Date.now());
let timer = null;
onMounted(() => { timer = setInterval(() => { now.value = Date.now(); }, 1000); });
onBeforeUnmount(() => { if (timer) clearInterval(timer); });

const previewCfg = computed(() => (form.anchor ? {
    anchor: new Date(form.anchor).toISOString(),
    interval_hours: Number(form.interval_hours),
    checkpoints_per_cycle: Number(form.checkpoints_per_cycle),
    ...parseLabel(form.label),
} : null));
const preview = computed(() => computeCycle(previewCfg.value, now.value));
const clockAt = (ms) => new Date(ms).toLocaleString([], { weekday: 'short', hour: 'numeric', minute: '2-digit' });
</script>

<template>
    <Head title="Cycle timing" />
    <AppLayout>
        <template #title><span class="inline-flex items-center gap-1.5 font-mono text-accent glow tracking-wide"><TimerReset :size="18" /> cycle timing</span></template>

        <div class="max-w-3xl mx-auto">
            <AdminNav current="cycle" class="mb-4" />

            <p class="mb-4 text-xs text-ink-dim border border-line rounded px-3 py-2 leading-relaxed">
                The Ingress checkpoint/cycle schedule is fixed — no score or game data is fetched. Watch one checkpoint roll over on
                your own scanner, enter that exact time — <span class="text-ink">including the seconds</span> — as the <span class="text-ink">anchor</span>
                (it begins a cycle), and the site extrapolates every future checkpoint and cycle with clock math. Confirm the checkpoints-per-cycle against what your scanner shows.
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <!-- form -->
                <form @submit.prevent="save" class="space-y-3 border border-line rounded-lg bg-surface p-3">
                    <div>
                        <label class="block text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">Anchor — a checkpoint start (your local time)</label>
                        <input v-model="form.anchor" type="datetime-local" step="1" :class="field" />
                        <p v-if="form.errors.anchor" class="mt-1 text-xs text-rose-400">{{ form.errors.anchor }}</p>
                    </div>
                    <div class="w-40">
                        <label class="block text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">Cycle at anchor — YYYY.NN</label>
                        <input v-model="form.label" placeholder="2026.26" :class="field" />
                        <p v-if="form.errors.label" class="mt-1 text-xs text-rose-400">{{ form.errors.label }}</p>
                    </div>
                    <div class="flex gap-3">
                        <div class="w-28">
                            <label class="block text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">Checkpoint (hrs)</label>
                            <input v-model="form.interval_hours" type="number" min="0.25" max="168" step="0.25" :class="field" />
                            <p v-if="form.errors.interval_hours" class="mt-1 text-xs text-rose-400">{{ form.errors.interval_hours }}</p>
                        </div>
                        <div class="w-32">
                            <label class="block text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">Checkpoints / cycle</label>
                            <input v-model="form.checkpoints_per_cycle" type="number" min="1" max="1000" step="1" :class="field" />
                            <p v-if="form.errors.checkpoints_per_cycle" class="mt-1 text-xs text-rose-400">{{ form.errors.checkpoints_per_cycle }}</p>
                        </div>
                    </div>
                    <button type="submit" :disabled="form.processing"
                        class="bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-sm font-semibold rounded px-3 py-1.5 disabled:opacity-40">
                        {{ form.processing ? 'saving…' : 'Save cycle timing' }}
                    </button>
                </form>

                <!-- live preview (what agents will see in the Cycle widget) -->
                <div class="border border-line rounded-lg bg-surface p-3">
                    <div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-2">Live preview</div>
                    <div v-if="!preview" class="text-sm text-ink-dim">Set an anchor to preview the schedule.</div>
                    <div v-else-if="preview.pending" class="text-sm text-ink-dim">
                        <div class="text-[10px] font-mono uppercase text-ink-faint">schedule starts in</div>
                        <div class="text-2xl font-mono text-accent glow tabular-nums">{{ fmtDur(preview.toStart) }}</div>
                    </div>
                    <div v-else class="space-y-2">
                        <div>
                            <div class="text-[10px] font-mono uppercase text-ink-faint">next checkpoint</div>
                            <div class="text-3xl leading-none font-mono text-accent glow tabular-nums">{{ fmtDur(preview.toNextCp) }}</div>
                        </div>
                        <div class="text-xs font-mono text-ink-dim">Checkpoint <span class="text-ink">{{ preview.cpInCycle }}</span> / {{ preview.M }} · cycle <span class="text-accent">{{ preview.label }}</span></div>
                        <div class="text-xs font-mono text-ink-faint border-t border-line/60 pt-2">cycle ends in {{ fmtDur(preview.toCycleEnd) }} · {{ clockAt(preview.cycleEndsAt) }}</div>
                    </div>
                </div>
            </div>

            <!-- MU field-scoring density (region-specific) -->
            <div class="mt-6 max-w-md border border-line rounded-lg bg-surface p-3">
                <div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-2">Field scoring — MU estimate</div>
                <p class="mb-3 text-xs text-ink-dim leading-relaxed">
                    The Mind-Unit estimate is <span class="text-ink">summed field area × this density</span>. True MU depends on the
                    population inside each field, so it's region-specific. To calibrate: run a real op, then set this to
                    <span class="text-ink">your actual MU ÷ the km² toady reported</span>.
                </p>
                <form @submit.prevent="saveMu" class="flex items-end gap-2">
                    <div class="w-32">
                        <label class="block text-[10px] font-mono uppercase tracking-wide text-ink-faint mb-1">People / km²</label>
                        <input v-model="muForm.density" type="number" min="1" max="50000" step="1" :class="field" />
                        <p v-if="muForm.errors.density" class="mt-1 text-xs text-rose-400">{{ muForm.errors.density }}</p>
                    </div>
                    <button type="submit" :disabled="muForm.processing" class="bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-sm font-semibold rounded px-3 py-1.5 disabled:opacity-40">{{ muForm.processing ? 'saving…' : 'Save' }}</button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
