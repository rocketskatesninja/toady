<script setup>
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { ChevronDown, ChevronRight, Maximize2, Minimize2, SquareArrowOutUpRight } from 'lucide-vue-next';

const props = defineProps({
    title: String,
    anchor: { type: String, default: null }, // widget key → DOM id for deep-link scroll ('w-<key>')
    icon: { type: [Object, Function], default: null }, // a Lucide component
    scroll: { type: Boolean, default: true },   // false for widgets that manage their own height (map/chat/dms)
    canToggleMode: { type: Boolean, default: false }, // offer the widget⇄page toggle
    mode: { type: String, default: 'widget' },        // 'widget' (in the grid) | 'page' (full-page)
    canCollapse: { type: Boolean, default: false },    // grid: show the collapse chevron
    collapsed: { type: Boolean, default: false },
    autoFit: { type: Boolean, default: false }, // mobile: report natural content height so the grid sizes to it (no inner scrollbar)
});
const emit = defineEmits(['toggle-mode', 'toggle-collapse', 'to-page', 'content-height']);

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
            <button v-if="canCollapse" @click="$emit('toggle-collapse')" :class="btn" :title="collapsed ? 'expand' : 'collapse'">
                <component :is="collapsed ? ChevronRight : ChevronDown" :size="16" />
            </button>
            <div v-else class="shrink-0 w-7"></div>
            <span class="flex-1 min-w-0 flex items-center justify-center gap-1.5 text-xs font-mono text-ink-dim uppercase tracking-wide">
                <component :is="icon" v-if="icon" :size="14" class="shrink-0" />
                <span class="truncate">{{ title }}</span>
            </span>
            <!-- right: widget⇄page toggle — maximize as a widget, minimize (dock) as a page -->
            <div class="shrink-0 min-w-7 flex items-center justify-end">
                <button v-if="canToggleMode && mode === 'widget'" @click="$emit('to-page')" :class="btn"
                    title="Move to its own page (stay on the dashboard)">
                    <SquareArrowOutUpRight :size="14" />
                </button>
                <button v-if="canToggleMode" @click="$emit('toggle-mode')" :class="btn"
                    :title="mode === 'widget' ? 'Open as a full page' : 'Dock as a dashboard widget'">
                    <component :is="mode === 'widget' ? Maximize2 : Minimize2" :size="15" />
                </button>
            </div>
        </div>
        <div v-show="!collapsed" class="flex-1 min-h-0" :class="scroll ? 'overflow-auto op-scroll' : 'overflow-hidden'">
            <div v-if="autoFit" ref="contentEl"><slot /></div>
            <slot v-else />
        </div>
    </div>
</template>
