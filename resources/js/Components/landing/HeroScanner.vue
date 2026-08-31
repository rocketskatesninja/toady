<script setup>
// A self-assembling fan-field: portals fade in, links draw between them, fields fill and gently breathe,
// agents pulse, and bright data blips run along the links — your team on one live scanner. Pure SVG + CSS
// animation (no JS timers, CSP-safe). Decorative → aria-hidden.
const portals = [
    { x: 200, y: 270 }, // 0: anchor
    { x: 70, y: 104 },  // 1
    { x: 142, y: 60 },  // 2
    { x: 224, y: 50 },  // 3
    { x: 300, y: 74 },  // 4
    { x: 350, y: 138 }, // 5
];
const links = [
    [0, 1], [0, 2], [0, 3], [0, 4], [0, 5],   // fan from the anchor
    [1, 2], [2, 3], [3, 4], [4, 5],            // the spine
];
const fields = [[0, 1, 2], [0, 2, 3], [0, 3, 4], [0, 4, 5]];
const agents = [
    { x: 168, y: 168, res: false },
    { x: 256, y: 150, res: true },
    { x: 214, y: 214, res: false },
];
const pt = (i) => `${portals[i].x},${portals[i].y}`;
defineProps({ delay: { type: Number, default: 0 } }); // offset (s) to stagger multiple instances out of lockstep
</script>

<template>
    <svg viewBox="0 0 400 300" class="hero-scanner w-full h-full" aria-hidden="true" role="presentation">
        <!-- fields (fade in, then gently breathe) -->
        <polygon v-for="(f, i) in fields" :key="'f' + i" class="field" :points="`${pt(f[0])} ${pt(f[1])} ${pt(f[2])}`"
            :style="{ animationDelay: `${delay + 1.4 + i * 0.18}s, ${delay + 2.5 + i * 0.18}s` }" />
        <!-- links -->
        <line v-for="(l, i) in links" :key="'l' + i" class="link"
            :x1="portals[l[0]].x" :y1="portals[l[0]].y" :x2="portals[l[1]].x" :y2="portals[l[1]].y"
            :style="{ animationDelay: delay + 0.7 + i * 0.11 + 's' }" />
        <!-- data blips travelling along each link, out from the anchor -->
        <line v-for="(l, i) in links" :key="'pl' + i" class="pulse" pathLength="100"
            :x1="portals[l[0]].x" :y1="portals[l[0]].y" :x2="portals[l[1]].x" :y2="portals[l[1]].y"
            :style="{ animationDelay: delay + 2.4 + i * 0.16 + 's' }" />
        <!-- agents -->
        <circle v-for="(a, i) in agents" :key="'a' + i" class="agent" :class="a.res ? 'res' : 'enl'"
            :cx="a.x" :cy="a.y" r="4.5" :style="{ animationDelay: delay + 2 + i * 0.22 + 's' }" />
        <!-- portals -->
        <g v-for="(p, i) in portals" :key="'p' + i" class="portal" :style="{ animationDelay: delay + 0.1 + i * 0.1 + 's' }">
            <circle :cx="p.x" :cy="p.y" :r="i === 0 ? 8 : 6" class="portal-glow" />
            <circle :cx="p.x" :cy="p.y" :r="i === 0 ? 5 : 3.5" class="portal-core" />
        </g>
    </svg>
</template>

<style scoped>
.hero-scanner { overflow: visible; }
.field { fill: color-mix(in srgb, var(--color-accent) 14%, transparent); stroke: color-mix(in srgb, var(--color-accent) 30%, transparent); stroke-width: 0.5; opacity: 0; animation: fieldIn 0.9s ease forwards, fieldBreathe 4.5s ease-in-out infinite; }
.link { stroke: color-mix(in srgb, var(--color-accent) 70%, transparent); stroke-width: 1.4; stroke-dasharray: 520; stroke-dashoffset: 520; animation: drawLink 0.9s ease-out forwards; }
.pulse { stroke: color-mix(in srgb, var(--color-accent) 90%, white); stroke-width: 2.4; stroke-linecap: round; stroke-dasharray: 4 200; stroke-dashoffset: 0; opacity: 0; animation: linkPulse 2.6s linear infinite; }
.portal { opacity: 0; transform-box: fill-box; transform-origin: center; animation: portalIn 0.5s cubic-bezier(0.2,0.85,0.3,1.3) forwards; }
.portal-glow { fill: color-mix(in srgb, var(--color-accent) 22%, transparent); }
.portal-core { fill: var(--color-accent); }
.agent { transform-box: fill-box; transform-origin: center; opacity: 0; animation: agentIn 0.4s ease forwards, agentPulse 2.4s ease-in-out infinite; }
.agent.enl { fill: #1cf0a0; }
.agent.res { fill: #38bdf8; }

@keyframes portalIn { from { opacity: 0; transform: scale(0); } to { opacity: 1; transform: scale(1); } }
@keyframes drawLink { to { stroke-dashoffset: 0; } }
@keyframes fieldIn { to { opacity: 1; } }
@keyframes fieldBreathe { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
@keyframes linkPulse { 0% { stroke-dashoffset: 0; opacity: 0; } 10% { opacity: 1; } 88% { opacity: 1; } 100% { stroke-dashoffset: -104; opacity: 0; } }
@keyframes agentIn { to { opacity: 1; } }
@keyframes agentPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }

@media (prefers-reduced-motion: reduce) {
    .field, .link, .portal, .agent { animation: none !important; opacity: 1; stroke-dashoffset: 0; transform: none; }
    .pulse { animation: none !important; opacity: 0; }
}
</style>
