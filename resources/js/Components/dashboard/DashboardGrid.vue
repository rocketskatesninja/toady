<script setup>
import { ref, watch, onMounted } from 'vue';
import { GridLayout, GridItem } from 'grid-layout-plus';
import DashboardWidgetCard from './DashboardWidgetCard.vue';
import WidgetRenderer from './WidgetRenderer.vue';
import { WIDGET_ICONS, OP_VIEWS } from '@/icons';
import { GRID_DRAG_OPTION, GRID_RESIZE_OPTION, installDragScrollGuard, installDragHaptic } from '@/drag';

const props = defineProps({
    layout: { type: Array, required: true },
    catalog: { type: Object, default: () => ({}) },
    syncKey: { type: Number, default: 0 }, // bump to force re-sync after a collapse / external change (no remount)
    mobile: { type: Boolean, default: false }, // mobile grid: auto-size the Plan widget to its content (no nested scrollbar)
});
const emit = defineEmits(['update:layout', 'toggle-mode', 'toggle-collapse', 'to-page']);

const clone = (l) => l.map((i) => ({ ...i }));
const sig = (l) => l.map((i) => i.i).sort().join('|');
const local = ref(clone(props.layout));

// Which widgets get the card's scroll wrapper (list views) vs. manage their own height — single source
// of truth in @/icons OP_VIEWS (Show.vue uses the same flag for full-page scroll, so they can't drift).
const SCROLL_VIEWS = new Set(OP_VIEWS.filter((v) => v.scroll).map((v) => v.key));

onMounted(() => { installDragScrollGuard(); installDragHaptic(); });

// Re-sync `local` from props when the widget SET changes (maximize/minimize) or an external change bumps
// syncKey (collapse). NEVER on position echoes — that would feed our own emit straight back into the grid
// → infinite loop → freeze.
watch([() => sig(props.layout), () => props.syncKey], () => { local.value = clone(props.layout); });

function onUpdated(next) {
    emit('update:layout', clone(next));
}

// A widget reports its natural content height (mobile auto-fit) → size its grid `h` to fit so nothing
// scrolls inside it. Grid geometry: row-height 38 + vertical margin 12 ⇒ n rows span (50n − 12)px; invert,
// round up, floor at the widget's minH. Write it straight into `local` (and up to the parent to persist) —
// content height doesn't depend on the container height, so this never loops.
function onContentHeight(key, px) {
    const item = local.value.find((i) => i.i === key);
    if (!item || item.collapsed) return;
    const h = Math.max(props.catalog[key]?.minH || 3, Math.ceil((px + 12) / 50));
    if (h === item.h) return;
    local.value = local.value.map((i) => (i.i === key ? { ...i, h } : i));
    emit('update:layout', clone(local.value));
}
</script>

<template>
    <GridLayout v-model:layout="local" :col-num="12" :row-height="38" :margin="[8, 12]"
        :is-draggable="true" :is-resizable="true" :responsive="false" :vertical-compact="true"
        :use-style-cursor="false" @layout-updated="onUpdated">
        <GridItem v-for="item in local" :key="item.i" :x="item.x" :y="item.y" :w="item.w" :h="item.h" :i="item.i"
            :min-w="catalog[item.i]?.minW || 3" :min-h="item.collapsed ? 1 : (catalog[item.i]?.minH || 3)"
            :is-resizable="!item.collapsed"
            drag-allow-from=".widget-drag" drag-ignore-from="button, a" :drag-option="GRID_DRAG_OPTION" :resize-option="GRID_RESIZE_OPTION">
            <DashboardWidgetCard :anchor="item.i" :title="catalog[item.i]?.label || item.i" :icon="WIDGET_ICONS[item.i]"
                :scroll="SCROLL_VIEWS.has(item.i)" :can-toggle-mode="true" mode="widget"
                :can-collapse="true" :collapsed="!!item.collapsed" :auto-fit="mobile && item.i === 'plan'"
                @toggle-mode="emit('toggle-mode', item.i)" @to-page="emit('to-page', item.i)" @toggle-collapse="emit('toggle-collapse', item.i)"
                @content-height="onContentHeight">
                <WidgetRenderer :widget="item.i" />
            </DashboardWidgetCard>
        </GridItem>
    </GridLayout>
</template>
