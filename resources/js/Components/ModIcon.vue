<script setup>
// Original line icons for the two hack-timer mods, drawn after the in-game silhouettes (not Niantic's artwork):
//   heat_sink  — a pinwheel of bent bars around a glowing core
//   multi_hack — two interlocking chevron diamonds with glowing points along the middle
// Everything is currentColor, so the rarity colour comes from the parent; the glow drops in daylight mode.
defineProps({
    type: { type: String, required: true },
    size: { type: Number, default: 18 },
});
const SPOKES = [0, 60, 120, 180, 240, 300];
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
        class="drop-shadow-[0_0_2px_currentColor] daylight:drop-shadow-none">
        <template v-if="type === 'heat_sink'">
            <path v-for="a in SPOKES" :key="a" d="M12 7.4 L12 3.6 L15.1 1.9" :transform="`rotate(${a} 12 12)`" />
            <circle cx="12" cy="12" r="3" fill="currentColor" fill-opacity="0.3" stroke="none" />
            <circle cx="12" cy="12" r="1.7" fill="currentColor" stroke="none" />
            <circle cx="12" cy="12" r="0.7" fill="white" fill-opacity="0.85" stroke="none" />
        </template>
        <template v-else>
            <path d="M4.6 12 L9 6.6 L13.4 12 L9 17.4 Z" stroke-width="1.5" />
            <path d="M10.6 12 L15 6.6 L19.4 12 L15 17.4 Z" stroke-width="1.5" />
            <path d="M2.9 10.1 L1.2 12 L2.9 13.9 M21.1 10.1 L22.8 12 L21.1 13.9" stroke-width="1.4" />
            <g stroke="none">
                <circle v-for="x in [8.2, 12, 15.8]" :key="x" :cx="x" cy="12" r="1.35" fill="currentColor" />
                <circle v-for="x in [8.2, 12, 15.8]" :key="'h' + x" :cx="x" cy="12" r="0.55" fill="white" fill-opacity="0.85" />
            </g>
        </template>
    </svg>
</template>
