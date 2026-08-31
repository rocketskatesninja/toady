<script setup>
import { ref, reactive } from 'vue';
import { usePage, router } from '@inertiajs/vue3';

// each type is gated server-side in Notifier::send; vibrate is handled client-side in useNotify
const NOTIF_TYPES = [
    { key: 'task', label: 'Assigned a directive' },
    { key: 'turn', label: 'Your turn (sequential ops)' },
    { key: 'go', label: 'An op goes live' },
    { key: 'dm', label: 'Direct messages' },
    { key: 'mention', label: '@mentioned in op comms' },
    { key: 'keys', label: 'Keys fully farmed' },
    { key: 'done', label: 'A directive was completed' },
    { key: 'join', label: 'An agent joined the op' },
    { key: 'report', label: 'New problem report', owner: true },
];

const user = usePage().props.auth.user;
const np = user?.notify_prefs || {};
const isOwner = !!user?.is_owner;
const prefs = reactive({
    vibrate: np.vibrate !== false,
    ...Object.fromEntries(NOTIF_TYPES.map((t) => [t.key, np[t.key] !== false])),
});

const ON = 'border-accent text-accent bg-emerald-500/10';
const OFF = 'border-line text-ink-faint';
const pill = 'shrink-0 font-mono text-[10px] uppercase border rounded px-2 py-0.5 w-12 text-center';

const saved = ref(false);
let timer;
function toggle(key) {
    prefs[key] = !prefs[key];
    router.put('/profile', { notify_prefs: { ...prefs } }, {
        preserveScroll: true, preserveState: true,
        onSuccess: () => { saved.value = true; clearTimeout(timer); timer = setTimeout(() => (saved.value = false), 1500); },
    });
}
</script>

<template>
    <div class="space-y-2.5">
        <label class="flex items-center justify-between gap-3 cursor-pointer">
            <span class="text-sm text-ink">Buzz my phone <span class="text-ink-faint text-xs font-mono">— vibrate on new alerts</span></span>
            <button type="button" @click="toggle('vibrate')" :class="[pill, prefs.vibrate ? ON : OFF]">{{ prefs.vibrate ? 'on' : 'off' }}</button>
        </label>
        <div class="flex items-center justify-between">
            <p class="text-[10px] font-mono text-ink-faint uppercase tracking-wide">Alert me about</p>
            <span v-if="saved" class="text-[10px] font-mono text-accent">saved ✓</span>
        </div>
        <template v-for="ty in NOTIF_TYPES" :key="ty.key">
            <label v-if="!ty.owner || isOwner" class="flex items-center justify-between gap-3 cursor-pointer">
                <span class="text-sm text-ink-dim">{{ ty.label }}</span>
                <button type="button" @click="toggle(ty.key)" :class="[pill, prefs[ty.key] ? ON : OFF]">{{ prefs[ty.key] ? 'on' : 'off' }}</button>
            </label>
        </template>
    </div>
</template>
