<script setup>
// Op-local portal intel: operatives edit (any time), everyone else sees it read-only. Fields are
// sized to their data — a short gate PIN isn't a full-width input.
const props = defineProps({
    wp: { type: Object, required: true },
    manage: { type: Boolean, default: false },
    hideEmpty: { type: Boolean, default: false }, // read-only + no intel → render nothing (used in Mission)
});
const emit = defineEmits(['save']); // (partialFields) => persist

const hasAny = () => ['gate_pin', 'parking', 'hours', 'access_notes', 'hazards'].some((k) => props.wp[k]);
const inp = 'bg-inset border border-line rounded px-1.5 py-1 text-sm text-ink focus:border-accent focus:outline-none';
const lbl = 'text-[10px] font-mono uppercase tracking-wide text-ink-faint';
</script>

<template>
    <div v-if="manage || hasAny() || !hideEmpty" class="text-xs">
        <div :class="lbl" class="mb-1.5">Portal intel <span v-if="manage" class="normal-case text-ink-faint/70">· only your op sees this</span></div>

        <!-- operative: editable (op-local; purged when the op closes) -->
        <div v-if="manage" class="space-y-2">
            <div class="grid grid-cols-3 gap-2">
                <label class="flex flex-col gap-0.5 min-w-0">
                    <span :class="lbl">Gate PIN</span>
                    <input :value="wp.gate_pin" @change="(e) => emit('save', { gate_pin: e.target.value })" placeholder="—" :class="inp" class="w-full font-mono" />
                </label>
                <label class="flex flex-col gap-0.5 min-w-0">
                    <span :class="lbl">Hours</span>
                    <input :value="wp.hours" @change="(e) => emit('save', { hours: e.target.value })" placeholder="—" :class="inp" class="w-full" />
                </label>
                <label class="flex flex-col gap-0.5 min-w-0">
                    <span :class="lbl">Parking</span>
                    <input spellcheck="true" :value="wp.parking" @change="(e) => emit('save', { parking: e.target.value })" placeholder="—" :class="inp" class="w-full" />
                </label>
            </div>
            <label class="flex flex-col gap-0.5">
                <span :class="lbl">Access notes</span>
                <textarea spellcheck="true" :value="wp.access_notes" @change="(e) => emit('save', { access_notes: e.target.value })" rows="2" placeholder="entry, stairs, lockbox…" :class="inp" class="w-full resize-y"></textarea>
            </label>
            <label class="flex flex-col gap-0.5">
                <span :class="lbl">Hazards</span>
                <textarea spellcheck="true" :value="wp.hazards" @change="(e) => emit('save', { hazards: e.target.value })" rows="2" placeholder="dogs, private property, traffic…" :class="inp" class="w-full resize-y"></textarea>
            </label>
        </div>

        <!-- agent: read-only -->
        <dl v-else-if="hasAny()" class="space-y-1.5">
            <div v-if="wp.gate_pin" class="flex gap-2"><dt :class="lbl" class="w-16 shrink-0 pt-0.5">Gate PIN</dt><dd class="font-mono font-medium text-accent">{{ wp.gate_pin }}</dd></div>
            <div v-if="wp.parking" class="flex gap-2"><dt :class="lbl" class="w-16 shrink-0 pt-0.5">Parking</dt><dd class="text-ink">{{ wp.parking }}</dd></div>
            <div v-if="wp.hours" class="flex gap-2"><dt :class="lbl" class="w-16 shrink-0 pt-0.5">Hours</dt><dd class="text-ink">{{ wp.hours }}</dd></div>
            <div v-if="wp.access_notes" class="flex gap-2"><dt :class="lbl" class="w-16 shrink-0 pt-0.5">Access</dt><dd class="text-ink whitespace-pre-line">{{ wp.access_notes }}</dd></div>
            <div v-if="wp.hazards" class="flex gap-2"><dt :class="lbl" class="w-16 shrink-0 pt-0.5">Hazards</dt><dd class="text-amber-400 whitespace-pre-line">{{ wp.hazards }}</dd></div>
        </dl>
        <p v-else class="text-ink-faint">No intel yet.</p>
    </div>
</template>
