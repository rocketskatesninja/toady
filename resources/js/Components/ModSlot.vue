<script setup>
import { ref, computed, nextTick, onBeforeUnmount } from 'vue';
import { Plus, Ban } from 'lucide-vue-next';
import ModIcon from '@/Components/ModIcon.vue';
import { RARITY, MOD_TYPES, parseMod } from '@/hacks';

// One of a portal's 4 mod slots on a hack timer: a coloured icon (or an empty dashed box) that opens a menu of
// the timer mods — Heat Sink and Multi-hack in each rarity — plus "empty slot". The menu is teleported to <body>
// and fixed-positioned so a widget card's overflow can't clip it.
const props = defineProps({
    mod: { type: String, default: null },
    index: { type: Number, required: true },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['pick']);

const cur = computed(() => parseMod(props.mod));

const open = ref(false);
const btn = ref(null);
const menu = ref(null);
const pos = ref({ top: 0, left: 0 });

function place() {
    const r = btn.value.getBoundingClientRect();
    const { offsetHeight: h, offsetWidth: w } = menu.value;
    const fitsBelow = r.bottom + 4 + h <= window.innerHeight - 8;
    pos.value = {
        top: fitsBelow ? r.bottom + 4 : Math.max(8, r.top - 4 - h),
        left: Math.min(Math.max(8, r.right - w), window.innerWidth - w - 8),
    };
}
async function toggle() {
    if (open.value) return close();
    open.value = true;
    await nextTick(); // the menu exists now (Vue repositions it before the browser paints)
    place();
    listen(true);
}
function close() { open.value = false; listen(false); }
function pick(key) {
    close();
    if (key !== props.mod) emit('pick', key);
}
function onPointer(e) { if (!menu.value?.contains(e.target) && !btn.value?.contains(e.target)) close(); }
function onKey(e) { if (e.key === 'Escape') close(); }
function onScroll(e) { if (!menu.value?.contains(e.target)) close(); }
function listen(on) {
    const fn = on ? 'addEventListener' : 'removeEventListener';
    document[fn]('pointerdown', onPointer, true);
    document[fn]('keydown', onKey);
    window[fn]('resize', close);
    window[fn]('scroll', onScroll, true);
}
onBeforeUnmount(() => listen(false));

</script>

<template>
    <button ref="btn" type="button" @click="toggle" :disabled="disabled" :aria-expanded="open" aria-haspopup="menu"
        class="size-8 shrink-0 inline-flex items-center justify-center rounded border transition-colors disabled:opacity-40"
        :class="cur ? RARITY[cur.rarity].tone : 'border-dashed border-line text-ink-faint hover:text-accent hover:border-accent'"
        :title="cur ? `Slot ${index + 1}: ${cur.label}` : `Slot ${index + 1}: empty — tap to add a mod`">
        <ModIcon v-if="cur" :type="cur.type" :size="20" />
        <Plus v-else :size="13" />
    </button>

    <Teleport to="body">
        <!-- icons only: Heat Sinks on the top row, Multi-hacks below (common · rare · very rare), "empty" on the right -->
        <div v-if="open" ref="menu" role="menu" class="fixed z-50 flex items-stretch gap-1.5 p-1.5 bg-surface border border-line rounded-lg shadow-xl"
            :style="{ top: pos.top + 'px', left: pos.left + 'px' }">
            <div class="grid grid-cols-3 gap-1.5">
                <template v-for="(typeLabel, type) in MOD_TYPES" :key="type">
                    <button v-for="(r, rk) in RARITY" :key="type + rk" type="button" role="menuitem" @click="pick(`${type}:${rk}`)"
                        :title="`${r.label} ${typeLabel}`" :aria-label="`${r.label} ${typeLabel}`"
                        class="size-10 inline-flex items-center justify-center rounded border transition-transform hover:scale-110"
                        :class="[r.tone, mod === `${type}:${rk}` && 'ring-2 ring-accent ring-offset-2 ring-offset-surface']">
                        <ModIcon :type="type" :size="24" />
                    </button>
                </template>
            </div>
            <button type="button" role="menuitem" @click="pick(null)" :disabled="!mod" title="Empty slot" aria-label="Empty slot"
                class="w-10 inline-flex items-center justify-center rounded border border-dashed border-line text-ink-faint hover:text-rose-400 hover:border-rose-500/50 disabled:opacity-30 disabled:pointer-events-none">
                <Ban :size="16" />
            </button>
        </div>
    </Teleport>
</template>
