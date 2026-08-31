<script setup>
import { ref, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Bell, BellOff } from 'lucide-vue-next';
import { pushSupported, isSubscribed, enablePush, disablePush } from '@/usePush';

const page = usePage();
const on = ref(false);
const busy = ref(false);
const supported = ref(true);

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
    <button v-if="supported" @click="toggle" :disabled="busy"
        :title="on ? 'Background alerts on — click to mute' : 'Enable background alerts'"
        class="flex items-center justify-center w-9 h-8 rounded transition-colors disabled:opacity-40"
        :class="on ? 'text-accent hover:bg-emerald-500/10' : 'text-ink-faint hover:text-accent'">
        <component :is="on ? Bell : BellOff" :size="18" />
    </button>
</template>
