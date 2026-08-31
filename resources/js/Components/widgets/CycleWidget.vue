<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import { Settings } from 'lucide-vue-next';
import { computeCycle, fmtDur } from '@/cycle';

// Global Ingress cycle timer. No score/game data is fetched — everything below is clock math off a single
// admin-set anchor (a checkpoint that begins a cycle) shared as page.props.cycle. Re-reads the clock 4×/sec
// so the countdown flips within ~¼s of the true second, instead of lagging up to a full second behind.
const page = usePage();
const cfg = computed(() => page.props.cycle || null);
const isAdmin = computed(() => !!page.props.auth?.user?.is_admin);

const now = ref(Date.now());
let timer = null;
onMounted(() => { timer = setInterval(() => { now.value = Date.now(); }, 250); });
onBeforeUnmount(() => { if (timer) clearInterval(timer); });

const model = computed(() => computeCycle(cfg.value, now.value));

const clockAt = (ms) => new Date(ms).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
const dayAt = (ms) => new Date(ms).toLocaleDateString([], { weekday: 'short' });
</script>

<template>
    <div class="px-3 py-3">
        <!-- not configured -->
        <div v-if="!model" class="text-sm text-ink-dim">
            <p>Cycle timing isn’t set up yet.</p>
            <Link v-if="isAdmin" href="/admin/cycle" class="mt-2 inline-flex items-center gap-1.5 text-xs font-mono text-accent hover:underline">
                <Settings :size="13" /> Set the cycle anchor
            </Link>
            <p v-else class="mt-1 text-xs text-ink-faint">A site admin sets the anchor.</p>
        </div>

        <!-- anchor is in the future -->
        <div v-else-if="model.pending" class="text-sm text-ink-dim">
            <div class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">schedule starts in</div>
            <div class="text-3xl font-mono text-accent glow tabular-nums">{{ fmtDur(model.toStart) }}</div>
        </div>

        <div v-else class="space-y-3">
            <!-- next checkpoint countdown -->
            <div>
                <div class="flex items-baseline justify-between">
                    <span class="text-[10px] font-mono uppercase tracking-wide text-ink-faint">next checkpoint</span>
                    <span class="text-[10px] font-mono text-ink-faint">at {{ clockAt(model.nextCpAt) }}</span>
                </div>
                <div class="text-4xl leading-none font-mono text-accent glow tabular-nums mt-0.5">{{ fmtDur(model.toNextCp) }}</div>
            </div>

            <!-- checkpoint position within the cycle -->
            <div>
                <div class="flex items-baseline justify-between mb-1">
                    <span class="text-xs font-mono text-ink-dim">Checkpoint <span class="text-ink">{{ model.cpInCycle }}</span> / {{ model.M }}</span>
                    <span class="text-[10px] font-mono text-accent">{{ model.label }}</span>
                </div>
                <div class="h-1.5 rounded-full bg-line/50 overflow-hidden">
                    <div class="h-full bg-accent rounded-full transition-all duration-500" :style="{ width: model.pct + '%' }"></div>
                </div>
            </div>

            <!-- cycle rollover -->
            <div class="flex items-baseline justify-between text-xs font-mono border-t border-line/60 pt-2">
                <span class="text-ink-faint">cycle ends in</span>
                <span class="text-ink-dim tabular-nums">{{ fmtDur(model.toCycleEnd) }} <span class="text-ink-faint">· {{ dayAt(model.cycleEndsAt) }} {{ clockAt(model.cycleEndsAt) }}</span></span>
            </div>
        </div>
    </div>
</template>
