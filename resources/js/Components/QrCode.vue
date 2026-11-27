<script setup>
import { computed } from 'vue';
import qrcode from 'qrcode-generator';

// Renders `value` as a scannable QR. Self-contained: the SVG is generated on-device (no network,
// no external image), so it works offline and under a strict CSP. Dark modules on a white plate for
// reliable scanning regardless of the app theme.
const props = defineProps({
    value: { type: String, required: true },
    size: { type: Number, default: 180 },
});

const svg = computed(() => {
    const qr = qrcode(0, 'M'); // type 0 = auto-size, error-correction level M
    qr.addData(props.value);
    qr.make();
    return qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
});
</script>

<template>
    <div class="bg-white rounded-md p-2 shrink-0" :style="{ width: size + 'px', height: size + 'px' }" v-html="svg"></div>
</template>

<style scoped>
div :deep(svg) { width: 100%; height: 100%; display: block; }
</style>
