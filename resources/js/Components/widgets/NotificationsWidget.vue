<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { CheckCheck, Settings, Trash2 } from 'lucide-vue-next';
import { usePoll } from '@/useLive';
import NotificationList from '@/Components/NotificationList.vue';
import NotificationSettings from '@/Components/NotificationSettings.vue';

// op-scoped feed (the widget lives inside an op dashboard)
const opId = usePage().props.op?.id;
const items = ref([]);
const showSettings = ref(false);
const hasUnread = computed(() => items.value.some((n) => !n.read));
const hasAny = computed(() => items.value.length > 0);
const notifyChanged = () => window.dispatchEvent(new CustomEvent('toady:notifications-changed'));

async function load() {
    try {
        const { data } = await window.axios.get('/notifications/feed', { params: { op: opId } });
        items.value = data.items;
    } catch (e) { /* keep last */ }
}
async function markRead(n) {
    if (n.read) return;
    n.read = true;
    try { await window.axios.post(`/notifications/${n.id}/read`); notifyChanged(); } catch (e) { /* */ }
}
async function markAll() {
    items.value.forEach((n) => (n.read = true));
    try { await window.axios.post('/notifications/read-all', { op: opId }); } catch (e) { /* */ }
    notifyChanged();
}
async function clearAll() {
    if (!items.value.length) return;
    if (!confirm(opId ? 'Clear all notifications for this op?' : 'Clear all notifications?')) return;
    items.value = [];
    try { await window.axios.post('/notifications/clear', { op: opId }); } catch (e) { /* */ }
    notifyChanged();
}

usePoll(load, 20000);

// A backgrounded tab throttles the poll above, so the panel can go stale until re-mount. Reload the
// moment the tab is refocused, and whenever the header's (persistent, reliable) unread poll signals a
// new notification arrived — so the list stays current without needing a page reload / re-login.
function onVisible() { if (!document.hidden) load(); }
onMounted(() => {
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('toady:notifications-changed', load);
});
onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', onVisible);
    window.removeEventListener('toady:notifications-changed', load);
});
</script>

<template>
    <div class="flex flex-col h-full">
        <div class="shrink-0 flex items-center justify-between gap-2 px-2 pt-2 pb-1.5">
            <div v-if="!showSettings" class="flex items-center gap-3 min-w-0">
                <button v-if="hasUnread" @click="markAll" class="flex items-center gap-1 text-[11px] font-mono text-ink-dim hover:text-accent">
                    <CheckCheck :size="14" /> mark all read
                </button>
                <button v-if="hasAny" @click="clearAll" class="flex items-center gap-1 text-[11px] font-mono text-ink-dim hover:text-rose-400" title="delete all notifications">
                    <Trash2 :size="14" /> clear
                </button>
                <span v-if="!hasAny" class="text-[11px] font-mono text-ink-faint truncate">Notifications</span>
            </div>
            <span v-else class="text-[11px] font-mono text-ink-faint truncate">Notification settings</span>
            <button @click="showSettings = !showSettings" :class="showSettings ? 'text-accent' : 'text-ink-dim hover:text-accent'" class="shrink-0" :title="showSettings ? 'back to alerts' : 'notification settings'"><Settings :size="14" /></button>
        </div>
        <div class="flex-1 min-h-0 overflow-auto op-scroll">
            <div v-if="showSettings" class="px-2 py-2"><NotificationSettings /></div>
            <NotificationList v-else :items="items" @read="markRead" />
        </div>
    </div>
</template>
