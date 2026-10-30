<script setup>
import { ref, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Bell, BellOff } from 'lucide-vue-next';
import { pushSupported, isSubscribed, enablePush, disablePush } from '@/usePush';
import { ui } from '@/ui';

// tile: a labeled phone-menu tile; otherwise the desktop menu's icon button
defineProps({ tile: { type: Boolean, default: false } });

const page = usePage();
const on = ref(false);
const busy = ref(false);
const supported = ref(true);
const title = computed(() => (on.value ? 'Background alerts are on for this device — tap to mute' : 'Get background alerts on this device'));

onMounted(async () => {
    supported.value = pushSupported();
    if (supported.value) on.value = await isSubscribed();
});

async function toggle() {
    if (busy.value) return;
    busy.value = true;
    try {
        on.value = on.value ? await disablePush() : await enablePush(page.props.vapidPublicKey);
    } catch (e) {
        alert(e.message || 'Could not change notifications.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <template v-if="supported">
        <button v-if="tile" @click="toggle" :disabled="busy" :class="ui.menuTile" :title="title">
            <component :is="on ? Bell : BellOff" :size="16" class="shrink-0" :class="on ? 'text-accent' : ''" />
            <span class="truncate">{{ on ? 'Alerts on' : 'Alerts off' }}</span>
        </button>
        <button v-else @click="toggle" :disabled="busy" :title="title"
            class="flex items-center justify-center w-9 h-8 rounded transition-colors disabled:opacity-40"
            :class="on ? 'text-accent hover:bg-emerald-500/10' : 'text-ink-faint hover:text-accent'">
            <component :is="on ? Bell : BellOff" :size="18" />
        </button>
    </template>
</template>
