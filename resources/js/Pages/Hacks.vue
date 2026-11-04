<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Timer, Plus, KeyRound } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import HackTimerCard from '@/Components/HackTimerCard.vue';
import HackAlertToggles from '@/Components/HackAlertToggles.vue';
import { useLivePoll } from '@/useLive';
import { lastFriendly } from '@/hacks';
import { ui, plural } from '@/ui';

// Your hack timers, op or not. A new one here has no portal — just an optional nickname (else "Timer N").
// Op timers (started from an op's Hack timers widget) show up here too, tagged with their op.
const props = defineProps({ timers: { type: Array, default: () => [] } });

useLivePoll(['timers'], 10000); // keep a second device in step

const nickname = ref('');
const creating = ref(false);
function create() {
    creating.value = true;
    router.post('/hacks', { label: nickname.value, friendly: lastFriendly() }, {
        preserveScroll: true,
        onSuccess: () => { nickname.value = ''; },
        onFinish: () => { creating.value = false; },
    });
}

const totalKeys = computed(() => props.timers.reduce((n, t) => n + t.keys, 0));
</script>

<template>
    <Head title="Hack timers" />
    <AppLayout>
        <template #title><span class="inline-flex items-center gap-1.5 font-mono text-accent glow tracking-wide"><Timer :size="18" /> Hack timers</span></template>

        <form @submit.prevent="create" class="flex items-end gap-2 max-w-md mb-4">
            <label class="flex-1 min-w-0">
                <span :class="ui.label">New timer</span>
                <input v-model="nickname" maxlength="80" placeholder="nickname (optional)" :class="ui.input" />
            </label>
            <button type="submit" :disabled="creating" :class="ui.primary"><Plus :size="14" /> Start</button>
        </form>

        <div class="flex items-center gap-3 mb-3 text-xs font-mono text-ink-dim">
            <template v-if="timers.length">
                <span>{{ plural(timers.length, 'timer') }}</span>
                <span class="inline-flex items-center gap-1"><KeyRound :size="12" class="text-ink-faint" />{{ plural(totalKeys, 'key') }} farmed</span>
            </template>
            <HackAlertToggles class="ml-auto" />
        </div>

        <div v-if="timers.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <HackTimerCard v-for="t in timers" :key="t.id" :timer="t" show-op />
        </div>
        <div v-else class="border border-dashed border-line rounded-lg px-4 py-10 text-center text-sm text-ink-dim max-w-md">
            Start a timer when you hack a portal. Tap <span class="text-accent">Hacked</span> each time, and it counts down
            your cooldown and keeps track of hacks left before burnout and keys farmed.
        </div>

        <p class="mt-6 text-[11px] text-ink-faint max-w-xl leading-relaxed">
            Timers are yours alone. One you don’t touch for 14 days is cleared automatically. Timers started from an op’s
            <span class="text-ink-dim">Hack timers</span> panel also count their keys toward your “keys mine” for that portal.
        </p>
    </AppLayout>
</template>
