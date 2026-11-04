<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Users, Bug, Images, TimerReset } from 'lucide-vue-next';

// Shared admin section nav — one right-aligned strip used by every admin page. `current` omits the active
// section. Add a section here once and it shows on all pages; per-page extras go in the default slot.
const props = defineProps({ current: { type: String, required: true } });

const SECTIONS = [
    { key: 'users', href: '/admin/users', label: 'users', icon: Users },
    { key: 'reports', href: '/admin/reports', label: 'reports', icon: Bug },
    { key: 'showcase', href: '/admin/showcase', label: 'showcase', icon: Images },
    { key: 'cycle', href: '/admin/cycle', label: 'cycle', icon: TimerReset },
];
const links = computed(() => SECTIONS.filter((s) => s.key !== props.current));
</script>

<template>
    <div class="flex items-center justify-end gap-4 text-xs font-mono text-ink-dim">
        <Link v-for="s in links" :key="s.key" :href="s.href" class="inline-flex items-center gap-1.5 hover:text-accent">
            <component :is="s.icon" :size="14" /> {{ s.label }}
        </Link>
        <slot />
    </div>
</template>
