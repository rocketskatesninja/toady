<script setup>
import { inject, ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import HackTimerCard from '@/Components/HackTimerCard.vue';
import HackAlertToggles from '@/Components/HackAlertToggles.vue';
import { lastFriendly } from '@/hacks';
import { ui } from '@/ui';

// The op's hack-timer panel: pick one of the op's portals by name to start its timer. Keys farmed here also move
// your "keys mine" count for that portal. The timers are yours — they live on after the op, on /hacks.
const c = inject('opctx');

const timing = computed(() => new Set(c.data.hackTimers.map((t) => t.op_waypoint_id)));
const pick = ref('');
const starting = ref(false);
function start() {
    if (!pick.value) return;
    starting.value = true;
    router.post(`/ops/${c.data.op.id}/hacks`, { op_waypoint_id: pick.value, friendly: lastFriendly() }, {
        preserveScroll: true, preserveState: true,
        onSuccess: () => { pick.value = ''; },
        onFinish: () => { starting.value = false; },
    });
}

// each portal's "keys mine" + the op's target, shown in its card — built once per poll, not per card per render
const keysByPortal = computed(() => new Map(c.data.waypoints.map((w) => [w.id, {
    mine: c.data.keyHoldings.find((h) => h.op_waypoint_id === w.id && h.user_id === c.me)?.qty ?? 0,
    needed: w.keys_needed || 0,
}])));
</script>

<template>
    <div class="h-full flex flex-col">
        <div class="shrink-0 px-2 pt-2 pb-1.5">
            <div class="flex items-center gap-1.5">
                <select v-model="pick" :class="ui.selectPlain" class="flex-1" aria-label="Portal">
                    <option value="" disabled>{{ c.data.waypoints.length ? 'Pick a portal…' : 'No portals in this op yet' }}</option>
                    <!-- numbered by position (already in plan order), like the map's markers -->
                    <option v-for="(w, i) in c.data.waypoints" :key="w.id" :value="w.id">{{ i + 1 }}. {{ w.title || 'Portal' }}{{ timing.has(w.id) ? ' · timing' : '' }}</option>
                </select>
                <button @click="start" :disabled="!pick || starting || timing.has(pick)" :class="ui.primary" :title="timing.has(pick) ? 'You already have a timer on this portal' : 'Start a hack timer on this portal'"><Plus :size="14" /> Start</button>
            </div>
            <HackAlertToggles class="justify-end mt-1" />
        </div>
        <div class="flex-1 min-h-0 overflow-y-auto op-scroll px-2 pb-2 space-y-2">
            <HackTimerCard v-for="t in c.data.hackTimers" :key="t.id" :timer="t" :keys="keysByPortal.get(t.op_waypoint_id) ?? null" />
            <p v-if="!c.data.hackTimers.length" class="py-6 text-center text-xs text-ink-faint">Pick a portal to start its hack timer.</p>
        </div>
    </div>
</template>
