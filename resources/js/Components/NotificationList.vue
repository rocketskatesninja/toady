<script setup>
import { Link } from '@inertiajs/vue3';
import { NOTIF_ICONS, NOTIF_FALLBACK } from '@/icons';
import { relTime } from '@/time';

defineProps({ items: { type: Array, default: () => [] } });
const emit = defineEmits(['read']);

const rel = (at) => relTime(at, { now: 'now' });
</script>

<template>
    <div v-if="items.length" class="flex flex-col">
        <component :is="i.url ? Link : 'div'" v-for="i in items" :key="i.id" :href="i.url || undefined"
            @click="emit('read', i)"
            class="flex items-start gap-2.5 px-2.5 py-2 border-b border-line/60 last:border-0 hover:bg-accent/5 cursor-pointer transition-colors"
            :class="i.read ? 'opacity-55' : ''">
            <component :is="NOTIF_ICONS[i.type] || NOTIF_FALLBACK" :size="16" class="shrink-0 mt-0.5 text-accent" />
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-1.5">
                    <span class="text-sm text-ink truncate flex-1">{{ i.title }}</span>
                    <span v-if="!i.read" class="shrink-0 w-1.5 h-1.5 rounded-full bg-accent"></span>
                    <span class="shrink-0 text-[10px] font-mono text-ink-faint">{{ rel(i.at) }}</span>
                </div>
                <p v-if="i.body" class="text-xs text-ink-dim truncate">{{ i.body }}</p>
            </div>
        </component>
    </div>
    <p v-else class="text-center text-ink-faint text-xs py-8">No notifications yet.</p>
</template>
