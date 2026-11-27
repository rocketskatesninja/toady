<script setup>
import { reactive } from 'vue';
import { lsGet, lsSet } from '@/ls';

// In-app confirm with a reliable "don't ask again" — Chrome only offers the native checkbox sporadically.
// ask(message, action, key?): if the key's skip-pref is set the action runs immediately, else the dialog opens.
const state = reactive({ open: false, message: '', key: null, remember: false, action: null });
function ask(message, action, key = null) {
    if (key && lsGet('toady:skip:' + key) === '1') { action(); return; }
    Object.assign(state, { open: true, message, key, remember: false, action });
}
function yes() {
    if (state.remember && state.key) lsSet('toady:skip:' + state.key, '1');
    state.open = false;
    const a = state.action; state.action = null; a?.();
}
function no() { state.open = false; state.action = null; }
defineExpose({ ask });
</script>

<template>
    <div v-if="state.open" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60" @click.self="no">
        <div class="w-full max-w-sm border border-line rounded-lg bg-surface p-4 shadow-2xl">
            <p class="text-sm text-ink">{{ state.message }}</p>
            <label v-if="state.key" class="mt-3 flex items-center gap-2 text-xs font-mono text-ink-dim cursor-pointer select-none">
                <input type="checkbox" v-model="state.remember" class="accent-emerald-500" /> don't ask again
            </label>
            <div class="mt-4 flex items-center justify-end gap-2">
                <button @click="no" class="text-sm font-mono text-ink-faint hover:text-ink px-3 py-1.5">cancel</button>
                <button @click="yes" class="bg-rose-500/80 hover:bg-rose-500 text-white font-mono text-sm font-semibold rounded px-3 py-1.5">confirm</button>
            </div>
        </div>
    </div>
</template>
