<script setup>
import { useNotify } from '@/useNotify';

const { toasts, dismiss } = useNotify();

// tone → card border (+ glow). success/default keep the accent glow; error goes rose with a plain shadow.
const CARD = {
    success: 'border-emerald-500/50 toast-glow',
    error: 'border-rose-500/50 shadow-lg',
    default: 'border-accent/40 toast-glow',
};
const TITLE = { error: 'text-rose-300', success: 'text-accent glow', default: 'text-accent glow' };
const card = (t) => CARD[t] || CARD.default;
const title = (t) => TITLE[t] || TITLE.default;
</script>

<template>
    <TransitionGroup name="toast" tag="div"
        class="fixed bottom-4 right-4 z-[80] flex flex-col gap-2 max-w-xs pointer-events-none">
        <div v-for="t in toasts" :key="t.id" @click="dismiss(t.id)"
            class="pointer-events-auto border bg-surface rounded-lg px-2.5 py-2 cursor-pointer" :class="card(t.tone)">
            <div v-if="t.title" class="text-[10px] font-mono uppercase tracking-wide" :class="title(t.tone)">{{ t.title }}</div>
            <div v-if="t.body" class="text-sm text-ink mt-0.5 break-words">{{ t.body }}</div>
        </div>
    </TransitionGroup>
</template>

<style scoped>
/* notifications scroll in/out from the right edge (auto-dismiss, or click to dismiss) */
.toast-enter-active, .toast-leave-active, .toast-move { transition: transform 0.35s ease, opacity 0.35s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateX(120%); }
@media (prefers-reduced-motion: reduce) { .toast-enter-active, .toast-leave-active, .toast-move { transition: none; } }
</style>
