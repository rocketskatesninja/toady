<script setup>
// A directive's second field: link target, mod (+ rarity or attack/defense type), or key count. Shared by the
// "add to every portal" composer and each directive row so both look and behave the same. Emits a patch:
// { links: [id] } | { mods: 'Very Rare Heat Sink' } | { qty: 6 }.
import { computed } from 'vue';
import { ui, up } from '@/ui';

const props = defineProps({
    action: { type: String, default: '' },
    links: { type: Array, default: null },   // link → [targetId]
    mods: { type: String, default: null },   // mod → "<variant> <name>" in one string
    qty: { type: Number, default: null },    // farm keys → count
    targets: { type: Array, default: () => [] }, // [{ id, label }] link targets
});
const emit = defineEmits(['update']);

const MODS = ['Shield', 'Aegis Shield', 'Heat Sink', 'Multi-hack', 'Force Amp', 'Turret', 'Link Amp', 'Ultra Link', 'Transmuter'];
// Some mods carry a qualifier, stored as a word-prefix in the `mods` string ("Rare Heat Sink", "Attack
// Transmuter") and shown abbreviated. Every other mod is a single variant: name only.
const RARITIES = [{ v: 'Common', s: 'C' }, { v: 'Rare', s: 'R' }, { v: 'Very Rare', s: 'VR' }];
const VARIANTS = {
    'Shield': { label: 'rarity', opts: RARITIES },
    'Heat Sink': { label: 'rarity', opts: RARITIES },
    'Multi-hack': { label: 'rarity', opts: RARITIES },
    'Transmuter': { label: 'type', opts: [{ v: 'Attack', s: 'ATK' }, { v: 'Defense', s: 'DEF' }] },
};
// every variant word, longest-first, so the split matches "Very Rare" before "Rare"
const PREFIXES = [...new Set(Object.values(VARIANTS).flatMap((g) => g.opts.map((o) => o.v)))].sort((a, b) => b.length - a.length);

const parts = computed(() => {
    const s = (props.mods || '').trim();
    const p = PREFIXES.find((x) => s.startsWith(x + ' '));
    return p ? { variant: p, mod: s.slice(p.length + 1) } : { variant: '', mod: s };
});
const variants = computed(() => VARIANTS[parts.value.mod] || null);
const join = (variant, mod) => (mod ? (variant ? `${variant} ${mod}` : mod) : null);

// picking a new mod keeps the variant only if it still applies
function setMod(mod) {
    const keep = VARIANTS[mod]?.opts.some((o) => o.v === parts.value.variant) ? parts.value.variant : '';
    emit('update', { mods: join(keep, mod) });
}
const setVariant = (v) => emit('update', { mods: join(v, parts.value.mod) });
</script>

<template>
    <select v-if="action === 'link'" :value="links?.[0] ?? ''" @change="(e) => emit('update', { links: e.target.value ? [+e.target.value] : [] })" :class="ui.select" title="link target">
        <option value="">— TARGET PORTAL —</option>
        <option v-for="t in targets" :key="t.id" :value="t.id">{{ up(t.label) }}</option>
    </select>
    <div v-else-if="action === 'mod'" class="flex gap-1.5">
        <select :value="parts.mod" @change="(e) => setMod(e.target.value)" :class="ui.select" title="mod">
            <option value="">— MOD —</option>
            <option v-for="m in MODS" :key="m" :value="m">{{ up(m) }}</option>
        </select>
        <select v-if="variants" :value="parts.variant" @change="(e) => setVariant(e.target.value)" :class="ui.select" class="w-24! shrink-0" :title="variants.label">
            <option value="">{{ up(variants.label) }}</option>
            <option v-for="o in variants.opts" :key="o.v" :value="o.v">{{ o.s }}</option>
        </select>
    </div>
    <div v-else-if="action === 'farm keys'" class="flex items-center gap-2">
        <input type="number" min="1" max="999" :value="qty" placeholder="#" @change="(e) => emit('update', { qty: +e.target.value || null })" :class="ui.num" class="w-20!" title="keys to farm" />
        <span :class="ui.hint">keys to farm</span>
    </div>
</template>
