<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { MessagesSquare, Smile, Send } from 'lucide-vue-next';
import { useMessages, msgTime } from '@/useMessages';
import { factionText } from '@/faction';
import Avatar from '@/Components/Avatar.vue';

const props = defineProps({
    opId: { type: String, required: true },
    me: Number,
    participants: { type: Array, default: () => [] },
    canModerate: { type: Boolean, default: false },
});

const others = computed(() => props.participants.filter((p) => p.user_id !== props.me));
const active = ref('op'); // 'op' = group op chat | <user_id> = a 1:1 DM
const isOp = computed(() => active.value === 'op');
const partner = computed(() => others.value.find((o) => o.user_id === active.value));

// avatar/faction lookup by user id (participants carry the avatar URL)
const byId = computed(() => Object.fromEntries(props.participants.map((p) => [p.user_id, p])));
const meP = computed(() => byId.value[props.me]);

const tabActive = 'bg-accent text-accent-ink border-accent font-semibold';
const tabIdle = 'border-line text-accent hover:border-accent/60';

const { messages, body, scroller, load, send, reset } = useMessages({
    urls: () => (isOp.value
        ? { get: `/ops/${props.opId}/chat` }
        : (active.value ? { get: `/ops/${props.opId}/dm/${active.value}` } : null)),
    isMine: (m) => (isOp.value ? m.user_id === props.me : m.mine),
    notifyFor: (m) => (isOp.value ? [`Op · ${m.user}`, m.body] : [`DM · ${partner.value?.callsign || 'agent'}`, m.body]),
});

// emoji picker for the message box (emojis are just unicode, so they render in messages as-is)
const inputEl = ref(null);
const showEmoji = ref(false);
const EMOJI_GROUPS = [
    { label: 'reactions', emojis: ['👍', '👎', '✅', '❌', '🔥', '💯', '🙏', '👀', '🎉', '❤️', '⭐', '💪', '😂', '😎', '🫡', '🥳', '👏', '🤔', '😅', '🤷', '🤯'] },
    { label: 'hands', emojis: ['👋', '🤝', '✊', '👊', '🙌', '🤙', '✌️', '👌', '🫶', '🤞', '🖐️', '🤛', '🤜', '🤲'] },
    { label: 'tactical', emojis: ['🎯', '📍', '🔑', '🛡️', '⚡', '💥', '🚀', '⚠️', '🧭', '📡', '🔋', '🏃', '🚗', '⏱️', '🆘', '☠️', '👁️', '🔦', '🗺️', '🚩', '🚲'] },
    { label: 'status', emojis: ['🟢', '🟡', '🔴', '🔵', '✋', '⏳', '📶', '🌧️', '🌫️', '☀️', '🌙', '🪫', '📴', '⛔'] },
];
function addEmoji(e) { body.value = (body.value || '') + e; inputEl.value?.focus(); }
function submitMsg() { send(); showEmoji.value = false; }

// op-chat moderation (delete your own / any as operative); DMs aren't deletable
async function del(m) {
    try {
        await window.axios.delete(`/ops/${props.opId}/chat/${m.id}`);
        messages.value = messages.value.filter((x) => x.id !== m.id);
    } catch (e) { /* */ }
}
function canDelete(m) { return isOp.value && (m.user_id === props.me || props.canModerate); }

// ---- unread indicator: which agents have an unread DM (from the notification feed) ----
const unreadIds = ref({}); // senderId -> [notificationId, ...]
const unreadFrom = computed(() => new Set(Object.keys(unreadIds.value).filter((k) => unreadIds.value[k].length).map(Number)));
const opUnreadIds = ref([]); // unread @mention notification ids → the Op tab's dot
const opUnread = computed(() => opUnreadIds.value.length > 0);
async function loadUnread() {
    try {
        const { data } = await window.axios.get('/notifications/feed', { params: { op: props.opId } });
        const map = {};
        const op = [];
        data.items.forEach((n) => {
            if (n.read) return;
            if (n.type === 'dm') {
                const id = Number(new URLSearchParams((n.url || '').split('?')[1] || '').get('dm'));
                if (id) (map[id] ??= []).push(n.id);
            } else if (n.type === 'mention') {
                op.push(n.id);
            }
        });
        unreadIds.value = map;
        opUnreadIds.value = op;
    } catch (e) { /* keep last */ }
}
async function markConvoRead(senderId) {
    const ids = unreadIds.value[senderId] || [];
    if (!ids.length) return;
    const { [senderId]: _, ...rest } = unreadIds.value;
    unreadIds.value = rest;
    await Promise.all(ids.map((id) => window.axios.post(`/notifications/${id}/read`).catch(() => {})));
    window.dispatchEvent(new CustomEvent('toady:notifications-changed'));
}

async function markOpRead() {
    const ids = opUnreadIds.value;
    if (!ids.length) return;
    opUnreadIds.value = [];
    await Promise.all(ids.map((id) => window.axios.post(`/notifications/${id}/read`).catch(() => {})));
    window.dispatchEvent(new CustomEvent('toady:notifications-changed'));
}

function pick(id) {
    active.value = id;
    reset();
    load();
    if (id === 'op') markOpRead();
    else markConvoRead(id);
}

// deep links: ?dm=<senderId> opens that DM; ?tab=op (a mention) opens the op chat
const page = usePage();
function openFromUrl() {
    const q = new URLSearchParams(page.url.split('?')[1] || '');
    const dm = Number(q.get('dm'));
    if (dm && dm !== active.value && others.value.some((o) => o.user_id === dm)) { pick(dm); return; }
    if (q.get('tab') === 'op' && !isOp.value) pick('op');
}

let unreadTimer = null;
onMounted(() => {
    loadUnread();
    openFromUrl();
    unreadTimer = setInterval(loadUnread, 7000);
    window.addEventListener('toady:notifications-changed', loadUnread);
});
onBeforeUnmount(() => {
    clearInterval(unreadTimer);
    window.removeEventListener('toady:notifications-changed', loadUnread);
});
watch(() => page.url, openFromUrl);
</script>

<template>
    <div class="flex flex-col h-full">
        <!-- tabs: the op group chat, then each agent for a 1:1 DM -->
        <div class="flex flex-wrap gap-1 px-1.5 py-2">
            <button @click="pick('op')" class="relative flex items-center gap-1 text-xs font-mono border rounded px-1.5 py-1 transition-colors"
                :class="isOp ? tabActive : tabIdle">
                <MessagesSquare :size="12" /> Op
                <span v-if="opUnread && !isOp" class="w-1.5 h-1.5 rounded-full bg-amber-400" title="new mention"></span>
            </button>
            <button v-for="p in others" :key="p.user_id" @click="pick(p.user_id)"
                class="relative flex items-center gap-1.5 text-xs font-mono border rounded px-1.5 py-1 transition-colors"
                :class="active === p.user_id ? tabActive : tabIdle">
                {{ p.callsign }}
                <span v-if="unreadFrom.has(p.user_id) && active !== p.user_id" class="w-1.5 h-1.5 rounded-full bg-amber-400" title="new message"></span>
            </button>
        </div>

        <div ref="scroller" class="flex-1 overflow-y-auto op-scroll px-1.5 py-2 space-y-2 min-h-0">
            <!-- op group chat: each sender shown, faction-coloured, with moderation -->
            <template v-if="isOp">
                <div v-for="m in messages" :key="m.id" class="group flex items-end gap-2" :class="m.user_id === me ? 'flex-row-reverse' : ''">
                    <Avatar :src="byId[m.user_id]?.avatar" :callsign="m.user" :faction="m.faction" :ring="byId[m.user_id]?.color" :size="36" class="shrink-0" />
                    <div class="flex flex-col min-w-0 max-w-[78%]" :class="m.user_id === me ? 'items-end' : 'items-start'">
                        <div class="flex items-baseline gap-1.5 text-[10px] font-mono mb-0.5 px-1" :class="m.user_id === me ? 'flex-row-reverse' : ''">
                            <span :class="m.user_id === me ? 'text-accent' : factionText(m.faction)">{{ m.user_id === me ? 'you' : m.user }}</span>
                            <span class="text-ink-faint">{{ msgTime(m.at) }}</span>
                            <button v-if="canDelete(m)" @click="del(m)" title="delete message" class="opacity-0 group-hover:opacity-100 text-ink-faint hover:text-rose-400">×</button>
                        </div>
                        <div class="rounded px-3 py-2 text-sm leading-snug break-words text-ink text-left" :class="m.user_id === me ? 'bg-emerald-500/15' : 'bg-line/70'">{{ m.body }}</div>
                    </div>
                </div>
                <p v-if="!messages.length" class="text-center text-xs text-ink-faint py-4">No messages yet.</p>
            </template>
            <!-- 1:1 DM: you / partner -->
            <template v-else>
                <div v-for="m in messages" :key="m.id" class="flex items-end gap-2" :class="m.mine ? 'flex-row-reverse' : ''">
                    <Avatar :src="m.mine ? meP?.avatar : partner?.avatar" :callsign="(m.mine ? meP?.callsign : partner?.callsign) || ''" :faction="m.mine ? meP?.faction : partner?.faction" :ring="m.mine ? meP?.color : partner?.color" :size="36" class="shrink-0" />
                    <div class="flex flex-col min-w-0 max-w-[78%]" :class="m.mine ? 'items-end' : 'items-start'">
                        <div class="text-[10px] font-mono mb-0.5 px-1 text-ink-faint"><span class="text-accent">{{ m.mine ? 'you' : partner?.callsign }}</span> · {{ msgTime(m.at) }}</div>
                        <div class="rounded px-3 py-2 text-sm leading-snug break-words text-ink text-left" :class="m.mine ? 'bg-emerald-500/15' : 'bg-line/70'">{{ m.body }}</div>
                    </div>
                </div>
                <p v-if="!messages.length" class="text-center text-xs text-ink-faint py-4">No messages yet — say hi.</p>
            </template>
        </div>

        <div class="relative border-t border-line">
            <!-- emoji picker — compact popup with the main menu's pop/stagger animation (backdrop click closes) -->
            <div v-if="showEmoji" @click="showEmoji = false" class="fixed inset-0 z-30"></div>
            <Transition name="menu-pop">
                <div v-if="showEmoji" class="menu-stagger absolute bottom-full left-0 mb-2 z-40 w-64 max-h-56 overflow-y-auto op-scroll bg-surface border border-line rounded-lg shadow-xl p-2" style="transform-origin: bottom left;">
                    <div v-for="g in EMOJI_GROUPS" :key="g.label" class="mb-1.5 last:mb-0">
                        <div class="text-[9px] font-mono uppercase tracking-wide text-ink-faint px-0.5 mb-0.5">{{ g.label }}</div>
                        <div class="grid grid-cols-7 gap-0.5">
                            <button v-for="e in g.emojis" :key="e" type="button" @click="addEmoji(e)" class="text-lg leading-none aspect-square rounded hover:bg-emerald-500/15">{{ e }}</button>
                        </div>
                    </div>
                </div>
            </Transition>
            <form @submit.prevent="submitMsg" class="flex items-center gap-1.5 p-1.5">
                <button type="button" @click="showEmoji = !showEmoji" title="emoji"
                    class="shrink-0 w-7 h-7 flex items-center justify-center rounded text-ink-dim hover:text-accent hover:bg-emerald-500/10" :class="{ 'text-accent bg-emerald-500/10': showEmoji }"><Smile :size="18" /></button>
                <input ref="inputEl" v-model="body" spellcheck="true" :placeholder="isOp ? (canModerate ? 'message the op… · @all to broadcast' : 'message the op…') : `message ${partner?.callsign || 'agent'}…`" maxlength="2000"
                    class="flex-1 min-w-0 bg-inset border border-line rounded px-1.5 py-1.5 text-sm focus:border-accent focus:outline-none" />
                <button type="submit" :disabled="!body.trim()" class="shrink-0 self-stretch flex items-center bg-accent hover:bg-emerald-400 text-accent-ink rounded px-2.5 disabled:opacity-40"><Send :size="15" /></button>
            </form>
        </div>
    </div>
</template>
