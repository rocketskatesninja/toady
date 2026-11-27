<script setup>
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { ChevronDown, ChevronRight, LayoutDashboard, Plus, SquareArrowOutUpRight, X } from 'lucide-vue-next';

const props = defineProps({
    title: String,
    anchor: { type: String, default: null }, // widget key → DOM id ('w-<key>')
    icon: { type: [Object, Function], default: null }, // a Lucide component
    scroll: { type: Boolean, default: true },   // false for widgets that manage their own height (map/chat/dms)
    mode: { type: String, default: 'widget' },        // 'widget' (in the grid) | 'page' (full-page)
    onDashboard: { type: Boolean, default: false },   // page: this widget is also on the dashboard (hides "add")
    pinned: { type: Boolean, default: false },        // widget: always on the dashboard (no open-page / close)
    collapsed: { type: Boolean, default: false },
    autoFit: { type: Boolean, default: false }, // mobile: report natural content height so the grid sizes to it (no inner scrollbar)
});
const emit = defineEmits(['toggle-collapse', 'open-page', 'close', 'add', 'back', 'content-height']);

const btn = 'shrink-0 w-7 h-7 flex items-center justify-center rounded text-ink-faint hover:text-accent hover:bg-emerald-500/10';

// Auto-fit (mobile only): report our natural height (header + un-clipped content) so the grid can size the
// widget to its content — no nested scrollbar, and it grows when a portal card expands. Content height is
// width-bound, so resizing the container back to fit never changes it → the observer settles in one pass.
const headerEl = ref(null);
const contentEl = ref(null);
let ro;
function measure() {
    if (!props.autoFit || props.collapsed || !contentEl.value) return;
    emit('content-height', props.anchor, (headerEl.value?.offsetHeight || 0) + contentEl.value.offsetHeight + 2);
}
onMounted(() => {
    if (!props.autoFit || typeof ResizeObserver === 'undefined') return;
    ro = new ResizeObserver(() => measure());
    if (contentEl.value) ro.observe(contentEl.value);
    nextTick(measure);
});
watch(() => props.collapsed, () => { if (props.autoFit) nextTick(measure); }); // re-fit when expanded back open
onBeforeUnmount(() => ro?.disconnect());
</script>

<template>
    <div :id="anchor ? 'w-' + anchor : undefined" class="h-full flex flex-col border border-line rounded-lg bg-surface overflow-hidden">
        <!-- the header bar IS the drag handle when on the grid (grid-layout-plus ignores its buttons) -->
        <div ref="headerEl" class="shrink-0 px-1 py-1 border-b border-line flex items-center gap-1 bg-emerald-500/5"
            :class="mode === 'widget' ? 'widget-drag' : ''">
            <!-- left: collapse chevron on a grid widget, else a spacer to keep the title centred -->
            <button v-if="mode === 'widget'" @click="$emit('toggle-collapse')" :class="btn" :title="collapsed ? 'expand' : 'collapse'">
                <component :is="collapsed ? ChevronRight : ChevronDown" :size="16" />
            </button>
            <div v-else class="shrink-0 w-7"></div>
            <span class="flex-1 min-w-0 flex items-center justify-center gap-1.5 text-xs font-mono text-ink-dim uppercase tracking-wide">
                <component :is="icon" v-if="icon" :size="14" class="shrink-0" />
                <span class="truncate">{{ title }}</span>
            </span>
            <!-- right: a widget opens its full page or leaves the dashboard; a page can be added to the dashboard
                 (when it isn't on it) and goes back to it. Every widget's full page is always available. -->
            <div class="shrink-0 min-w-7 flex items-center justify-end">
                <template v-if="mode === 'widget' && !pinned">
                    <button @click="$emit('open-page')" :class="btn" title="Open as a full page"><SquareArrowOutUpRight :size="14" /></button>
                    <button @click="$emit('close')" :class="btn" title="Remove from the dashboard"><X :size="15" /></button>
                </template>
                <template v-else-if="mode === 'page'">
                    <button v-if="!onDashboard" @click="$emit('add')" :class="btn" title="Add to the dashboard"><Plus :size="15" /></button>
                    <button @click="$emit('back')" :class="btn" title="Back to the dashboard"><LayoutDashboard :size="14" /></button>
                </template>
            </div>
        </div>
        <div v-show="!collapsed" class="flex-1 min-h-0" :class="scroll ? 'overflow-auto op-scroll' : 'overflow-hidden'">
            <div v-if="autoFit" ref="contentEl"><slot /></div>
            <slot v-else />
        </div>
    </div>
</template>
