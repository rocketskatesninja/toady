<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { BookOpen, Timer, ChevronRight } from 'lucide-vue-next';

const props = defineProps({
    linkLevels: { type: Array, default: () => [] },
    soloMaxKm: Number,
    resonators: { type: Array, default: () => [] },
    ap: { type: Object, default: () => ({}) },
    agentLevels: { type: Array, default: () => [] },
    mechanics: Object,
});

const pct = (x) => Math.round(x * 100);
const fmt = (n) => n.toLocaleString();

const mods = computed(() => {
    const m = props.mechanics;
    return [
        { name: 'Portal Shield', detail: `mitigate XMP damage · ${m.shield.common} / ${m.shield.rare} / ${m.shield.very_rare}%` },
        { name: 'Heat Sink', detail: `cut hack cooldown · ${pct(m.heat_sink.common)} / ${pct(m.heat_sink.rare)} / ${pct(m.heat_sink.very_rare)}%` },
        { name: 'Multi-hack', detail: `extra hacks · +${m.multi_hack.common} / +${m.multi_hack.rare} / +${m.multi_hack.very_rare}` },
        { name: 'Link Amp', detail: "extend the portal's outgoing link range (stacks, diminishing)" },
        { name: 'SoftBank Ultra Link', detail: 'very rare — big link-range boost + extra outgoing links' },
        { name: 'Force Amp', detail: "rare — boost the portal's XM damage to attackers" },
        { name: 'Turret', detail: 'rare — faster, more critical portal attacks' },
    ];
});

const glyphs = ['Create', 'Destroy', 'Capture', 'Link', 'Field', 'Path', 'Portal', 'Future', 'Past', 'Human', 'Mind', 'Pure', 'Enlightened', 'Resistance', 'Victory', 'Defend'];

const cardHead = 'px-2 py-2.5 border-b border-line text-xs font-mono text-ink-dim uppercase';
</script>

<template>
    <Head title="Reference" />
    <AppLayout>
        <template #title><span class="inline-flex items-center gap-1.5 font-mono text-accent glow tracking-wide"><BookOpen :size="18" /> Mechanics reference</span></template>

        <p class="text-xs text-ink-faint mb-5">Source-verified constants. Trust these over recall.</p>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- link range -->
            <section class="border border-line rounded-lg bg-surface overflow-hidden">
                <div :class="cardHead">Link range · 160 m × L⁴</div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="r in linkLevels" :key="r.level" class="border-b border-line" :class="{ 'text-accent': r.level === 5 }">
                            <td class="px-2 py-1.5 font-mono">avg L{{ r.level }}</td>
                            <td class="px-2 py-1.5 text-right font-mono">{{ r.km }} km</td>
                        </tr>
                    </tbody>
                </table>
                <p class="px-2 py-2 text-xs text-accent/70 border-t border-line">Solo max (avg reso 5.625) ≈ {{ soloMaxKm }} km.</p>
            </section>

            <!-- hacking -->
            <section class="border border-line rounded-lg bg-surface overflow-hidden">
                <div :class="cardHead">Hacking / keys</div>
                <dl class="text-sm divide-y divide-line">
                    <div class="flex justify-between px-2 py-1.5"><dt class="text-ink-dim">Hacks before burnout</dt><dd class="font-mono">{{ mechanics.hacks_before_burnout }} / {{ mechanics.burnout_hours }} hr</dd></div>
                    <div class="flex justify-between px-2 py-1.5"><dt class="text-ink-dim">Cooldown (own)</dt><dd class="font-mono">{{ mechanics.cooldown_own_min }} min</dd></div>
                    <div class="flex justify-between px-2 py-1.5"><dt class="text-ink-dim">Cooldown (neutral/enemy)</dt><dd class="font-mono">{{ mechanics.cooldown_enemy_min }} min</dd></div>
                    <div class="flex justify-between px-2 py-1.5"><dt class="text-ink-dim">Key drop / hack</dt><dd class="font-mono">~{{ mechanics.key_drop_pct }}%</dd></div>
                    <div class="flex justify-between px-2 py-1.5"><dt class="text-ink-dim">Heat Sink (C/R/VR)</dt><dd class="font-mono">−{{ pct(mechanics.heat_sink.common) }} / −{{ pct(mechanics.heat_sink.rare) }} / −{{ pct(mechanics.heat_sink.very_rare) }}%</dd></div>
                    <div class="flex justify-between px-2 py-1.5"><dt class="text-ink-dim">Multi-hack (C/R/VR)</dt><dd class="font-mono">+{{ mechanics.multi_hack.common }} / +{{ mechanics.multi_hack.rare }} / +{{ mechanics.multi_hack.very_rare }}</dd></div>
                </dl>
            </section>

            <!-- resonators -->
            <section class="border border-line rounded-lg bg-surface overflow-hidden">
                <div :class="cardHead">Resonators · {{ mechanics.portal_slots }} slots · deploy ≤ {{ mechanics.deploy_range_m }} m</div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="r in resonators" :key="r.level" class="border-b border-line">
                            <td class="px-2 py-1.5 font-mono">L{{ r.level }}</td>
                            <td class="px-2 py-1.5 text-right font-mono text-ink-dim">max ×{{ r.count }} / agent</td>
                        </tr>
                    </tbody>
                </table>
                <p class="px-2 py-2 text-xs text-ink-faint border-t border-line">Portal level = ⌊avg of all 8⌋. Solo you reach <span class="text-accent">L5</span> (1×L8 + 1×L7 + 2×L6 + 2×L5 + 2×L4); an L8 portal needs 8 agents.</p>
            </section>

            <!-- mods -->
            <section class="border border-line rounded-lg bg-surface overflow-hidden">
                <div :class="cardHead">Mods · {{ mechanics.mod_slots }} slots · max {{ mechanics.mods_per_agent }} / agent</div>
                <dl class="text-sm divide-y divide-line">
                    <div v-for="mod in mods" :key="mod.name" class="px-2 py-1.5">
                        <dt class="text-ink">{{ mod.name }}</dt>
                        <dd class="text-[11px] font-mono text-ink-faint">{{ mod.detail }}</dd>
                    </div>
                </dl>
            </section>

            <!-- AP -->
            <section class="border border-line rounded-lg bg-surface overflow-hidden">
                <div :class="cardHead">AP per action</div>
                <dl class="text-sm divide-y divide-line">
                    <div v-for="(pts, action) in ap" :key="action" class="flex justify-between gap-2 px-2 py-1.5"><dt class="text-ink-dim">{{ action }}</dt><dd class="font-mono shrink-0">{{ fmt(pts) }}</dd></div>
                </dl>
            </section>

            <!-- agent levels -->
            <section class="border border-line rounded-lg bg-surface overflow-hidden">
                <div :class="cardHead">Agent levels · AP + badges</div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="l in agentLevels" :key="l.level" class="border-b border-line">
                            <td class="px-2 py-1.5 font-mono" :class="l.level >= 9 ? 'text-accent' : ''">L{{ l.level }}</td>
                            <td class="px-2 py-1.5 text-right font-mono">{{ fmt(l.ap) }}</td>
                            <td class="px-2 py-1.5 text-right text-[10px] font-mono text-ink-faint">{{ l.badges || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- links & fields -->
            <section class="border border-line rounded-lg bg-surface overflow-hidden">
                <div :class="cardHead">Links & fields</div>
                <div class="px-2 py-2 text-sm text-ink-dim space-y-2">
                    <p>To link: stand at the origin portal (≤ {{ mechanics.deploy_range_m }} m), your faction owns it, you <span class="text-ink">hold a Key</span> to the destination, and it's within link range.</p>
                    <p>A link <span class="text-ink">can't cross</span> any existing link (friendly or enemy), and you can't link out from under an enemy field.</p>
                    <p>Three portals all linked to each other = a <span class="text-accent">Control Field</span>. Field score ≈ <span class="font-mono">Mind Units</span> — the population enclosed. Layered / nested fields each count.</p>
                </div>
            </section>

            <!-- combat -->
            <section class="border border-line rounded-lg bg-surface overflow-hidden">
                <div :class="cardHead">Combat</div>
                <dl class="text-sm divide-y divide-line">
                    <div class="px-2 py-1.5"><dt class="text-ink">XMP Burster</dt><dd class="text-[11px] font-mono text-ink-faint">area XM blast — most damage at center, falls off with distance; hits every reso + mod in radius. Higher level = more damage + bigger radius. Reduced by Shield mitigation.</dd></div>
                    <div class="px-2 py-1.5"><dt class="text-ink">Ultra Strike</dt><dd class="text-[11px] font-mono text-ink-faint">tight radius, high punch — finish a specific resonator.</dd></div>
                    <div class="px-2 py-1.5"><dt class="text-ink">Recharge</dt><dd class="text-[11px] font-mono text-ink-faint">restore resonator health + portal XM; works remotely if you hold a Key, weaker with distance.</dd></div>
                </dl>
            </section>

            <!-- XM -->
            <section class="border border-line rounded-lg bg-surface overflow-hidden">
                <div :class="cardHead">XM (Exotic Matter)</div>
                <div class="px-2 py-2 text-sm text-ink-dim space-y-1.5">
                    <p>Powers every action — deploy, hack, link, attack, recharge.</p>
                    <p>Collect it by <span class="text-ink">walking</span>; denser in populated areas. Your XM capacity grows with your level.</p>
                    <p>Run dry and you can't act until you gather more on foot.</p>
                </div>
            </section>

            <!-- glyph hacking -->
            <section class="border border-line rounded-lg bg-surface overflow-hidden">
                <div :class="cardHead">Glyph hacking</div>
                <div class="px-2 py-2 text-sm text-ink-dim space-y-2">
                    <p>Optional during a hack: trace the glyph sequence for <span class="text-accent">bonus AP + items</span>. Sequence length grows with portal level (1 → 5 glyphs). Faster, accurate, and hint-free = bigger bonus.</p>
                    <div class="flex flex-wrap gap-1">
                        <span v-for="g in glyphs" :key="g" class="text-[10px] font-mono border border-line rounded px-1.5 py-0.5 text-ink-faint">{{ g }}</span>
                    </div>
                </div>
            </section>
        </div>

        <!-- hack timers live on their own page now (cooldown, burnout, mods, keys — and phone alerts) -->
        <Link href="/hacks" class="mt-5 flex items-center gap-3 border border-emerald-500/20 rounded-lg bg-surface px-3 py-3 hover:border-accent group">
            <Timer :size="20" class="shrink-0 text-accent" />
            <span class="flex-1 min-w-0">
                <span class="block text-sm text-ink">Hack timers</span>
                <span class="block text-xs text-ink-faint">Cooldown and burnout per portal, with its Heat Sinks and Multi-hacks, keys farmed, and a buzz when you can hack again.</span>
            </span>
            <ChevronRight :size="18" class="shrink-0 text-ink-faint group-hover:text-accent" />
        </Link>
    </AppLayout>
</template>
