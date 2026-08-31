<script setup>
// The toady brand mark — the ▲ drawn as an Ingress control field: three portal
// nodes joined by links (three portals linked = a field = a triangle, the thing
// the app is for). It uses currentColor, so it inherits the faction accent from
// its surrounding text-accent context — green for ENL, blue for RES — with no
// faction logic here. The glow mirrors the wordmark's .glow and drops in daylight;
// both live in app.css under .brand-mark.
defineProps({
    // height relative to the surrounding text, matching the old ▲ glyph (any CSS length)
    size: { type: String, default: '1.2em' },
    // wordmark glow — pass false in the dimmed footers, which drop .glow on the text too
    glow: { type: Boolean, default: true },
});
</script>

<template>
    <svg class="brand-mark" :class="{ 'brand-mark--glow': glow }" :style="{ height: size }"
        viewBox="0 0 100 100" aria-hidden="true" focusable="false">
        <polygon class="bm-fill" points="50,15 12,82 88,82" />
        <polygon class="bm-link" points="50,15 12,82 88,82" stroke-width="4" stroke-linejoin="round" />
        <circle class="bm-ring" cx="50" cy="15" r="6" stroke-width="2.5" /><circle class="bm-core" cx="50" cy="15" r="2.4" />
        <circle class="bm-ring" cx="12" cy="82" r="6" stroke-width="2.5" /><circle class="bm-core" cx="12" cy="82" r="2.4" />
        <circle class="bm-ring" cx="88" cy="82" r="6" stroke-width="2.5" /><circle class="bm-core" cx="88" cy="82" r="2.4" />
    </svg>
</template>
