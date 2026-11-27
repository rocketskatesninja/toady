<script setup>
import { ref, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Bell, BellOff, Vibrate, VibrateOff, Smartphone } from 'lucide-vue-next';
import { pushSupported, enablePush } from '@/usePush';
import { useNotify } from '@/useNotify';
import { useHackAlerts } from '@/hacks';
import { ui } from '@/ui';

// The hack-timer alert switches (saved per user, so they follow you across devices): alerts on/off and buzz on/off.
// When alerts are on but THIS device hasn't turned push on, offer it — without push a phone only alerts while
// the page is open (and Firefox for Android can't vibrate from a page at all).
const page = usePage();
const { alerts, buzz, pushSubscribed } = useHackAlerts();
const { notify } = useNotify();
const deviceUnset = computed(() => pushSupported() && pushSubscribed.value === false);
const busy = ref(false);

// send only the switch that changed — the server merges it into the rest of your notification settings
const save = (patch) => router.put('/profile', { notify_prefs: patch }, { preserveScroll: true, preserveState: true });

async function setUpDevice() {
    busy.value = true;
    try {
        await enablePush(page.props.vapidPublicKey);
    } catch (e) {
        notify('Couldn’t turn on phone alerts', e.message || '', 'error');
    } finally {
        busy.value = false;
    }
}
async function toggleAlerts() {
    const on = !alerts.value;
    save({ hacks: on });
    if (on && deviceUnset.value) await setUpDevice();
}
</script>

<template>
    <div class="flex items-center gap-0.5">
        <button v-if="alerts && deviceUnset" type="button" @click="setUpDevice" :disabled="busy"
            class="h-8 shrink-0 inline-flex items-center gap-1 px-1.5 mr-0.5 rounded border border-amber-500/50 text-[10px] font-mono uppercase text-amber-300 daylight:text-amber-700 hover:brightness-125 disabled:opacity-40"
            title="This device only alerts while the page is open — tap to get alerts here in the background too">
            <Smartphone :size="13" /> this device
        </button>
        <button type="button" @click="toggleAlerts" :class="ui.iconToggle(alerts)" :aria-pressed="alerts"
            :title="alerts ? 'Timer alerts on — you’re told when a timer runs out. Tap to turn off.' : 'Timer alerts off — tap to be told when a timer runs out'">
            <component :is="alerts ? Bell : BellOff" :size="16" />
        </button>
        <button type="button" @click="save({ hacks_buzz: !buzz })" :disabled="!alerts" :class="ui.iconToggle(alerts && buzz)" :aria-pressed="buzz"
            :title="buzz ? 'Buzz on — timer alerts vibrate. Tap for silent alerts.' : 'Buzz off — timer alerts are silent. Tap to vibrate.'">
            <component :is="buzz ? Vibrate : VibrateOff" :size="16" />
        </button>
    </div>
</template>
