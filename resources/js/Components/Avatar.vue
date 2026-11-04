<script setup>
import { factionChip } from '@/faction';

// Profile photo if set, otherwise a faction-tinted initial. Reusable across roster / chat / etc.
// `ring` (a #rrggbb colour) overrides the border into a coloured ring — and tints the placeholder
// initial to match — e.g. an operator-assigned agent colour.
defineProps({
    src: { type: String, default: null },
    callsign: { type: String, default: '' },
    faction: { type: String, default: null },
    size: { type: Number, default: 28 },
    ring: { type: String, default: null },
});
</script>

<template>
    <img v-if="src" :src="src" :alt="callsign" loading="lazy"
        class="rounded-full object-cover shrink-0 bg-inset border" :class="ring ? 'border-2' : 'border-line'"
        :style="{ width: size + 'px', height: size + 'px', ...(ring ? { borderColor: ring } : {}) }" />
    <span v-else class="rounded-full shrink-0 inline-flex items-center justify-center font-mono font-semibold border uppercase"
        :class="[factionChip(faction), ring ? 'border-2' : '']"
        :style="{ width: size + 'px', height: size + 'px', fontSize: Math.round(size * 0.42) + 'px', ...(ring ? { borderColor: ring, color: ring } : {}) }">
        {{ (callsign || '?').charAt(0) }}
    </span>
</template>
