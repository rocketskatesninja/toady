<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';

// The op's settings form. Mounted only while open, so it always starts from the op as it is now.
const props = defineProps({ op: Object, editable: Boolean }); // editable: title, briefing & type lock once the op is active
const emit = defineEmits(['close', 'close-op']);
const header = reactive({ name: props.op.name, description: props.op.description || '', type: props.op.type, goals: props.op.goals || '', allow_export: props.op.allow_export });
function saveHeader() { router.put(`/ops/${props.op.id}`, header, { preserveScroll: true, onSuccess: () => emit('close') }); }
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-start justify-center p-4 pt-20 bg-black/60" @click.self="$emit('close')">
        <form @submit.prevent="saveHeader" class="w-full max-w-lg border border-line rounded-lg bg-surface p-3 space-y-2 shadow-2xl">
            <div class="text-xs font-mono uppercase tracking-wide text-ink-dim">Edit op</div>
            <p v-if="!editable" class="text-[11px] font-mono text-amber-300/80 daylight:text-amber-700">Title, briefing &amp; type are locked while the op is active — set it back to planning to edit them.</p>
            <input v-model="header.name" :disabled="!editable" class="w-full bg-inset border border-line rounded px-1.5 py-2 text-sm disabled:opacity-50 disabled:cursor-not-allowed" />
            <textarea v-model="header.description" spellcheck="true" :disabled="!editable" rows="2" placeholder="description / directives" class="w-full bg-inset border border-line rounded px-1.5 py-2 text-sm disabled:opacity-50 disabled:cursor-not-allowed"></textarea>
            <textarea v-model="header.goals" spellcheck="true" :disabled="!editable" rows="2" placeholder="goals (shown in the Goals & notes widget)" class="w-full bg-inset border border-line rounded px-1.5 py-2 text-sm disabled:opacity-50 disabled:cursor-not-allowed"></textarea>
            <label class="flex items-center gap-2 text-xs font-mono text-ink-dim">
                <input type="checkbox" v-model="header.allow_export" class="accent-emerald-500" /> let agents export this plan
            </label>
            <div class="flex flex-wrap gap-2 items-center">
                <select v-model="header.type" :disabled="!editable" class="bg-inset border border-line rounded px-1.5 py-2 text-sm font-mono disabled:opacity-50 disabled:cursor-not-allowed">
                    <option value="any_order">any order</option><option value="visible">sequential · visible</option><option value="hidden">sequential · hidden</option>
                </select>
                <button type="submit" class="bg-accent hover:bg-emerald-400 text-accent-ink font-mono text-xs font-semibold rounded px-1.5 py-1.5">save</button>
                <button type="button" @click="$emit('close')" class="text-xs text-ink-faint px-2">cancel</button>
                <button type="button" @click="$emit('close-op')" class="ml-auto text-xs text-ink-faint hover:text-rose-400 font-mono">close + purge op</button>
            </div>
        </form>
    </div>
</template>
