import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useNotify } from '@/useNotify';

/**
 * Shared poll-based message thread used by the comms panel (op chat + DMs).
 *
 * Callers supply the endpoints + per-message helpers; this owns the 3s
 * visibility-gated poll loop, the notify-on-genuinely-new logic (skips the
 * initial backlog), optimistic send, and scroll-to-bottom.
 *
 *   urls():            { get } endpoint to fetch the thread, or null to pause polling
 *   isMine(m):         is this message from the current user (skip notifying yourself)
 *   notifyFor(m):      [title, body] for an incoming message
 */
export function useMessages({ urls, isMine, notifyFor }) {
    const { notify } = useNotify();
    const messages = ref([]);
    const body = ref('');
    const scroller = ref(null);
    let timer = null;
    let lastSeen = 0;
    let primed = false;   // skip the initial backlog; only buzz on genuinely new messages

    function scrollDown() {
        nextTick(() => { if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight; });
    }

    async function load() {
        const u = urls();
        if (!u) return;
        try {
            const { data } = await window.axios.get(u.get);
            if (urls()?.get !== u.get) return; // thread switched mid-request (mount race / tab change) — drop the stale response
            const grow = data.length !== messages.value.length;
            if (primed) {
                data.filter((m) => m.id > lastSeen && !isMine(m)).forEach((m) => notify(...notifyFor(m)));
            }
            if (data.length) lastSeen = data[data.length - 1].id;
            primed = true;
            messages.value = data;
            if (grow) scrollDown();
        } catch (e) { /* offline tick */ }
    }

    async function send() {
        const u = urls();
        const t = body.value.trim();
        if (!t || !u) return;
        body.value = '';
        const { data } = await window.axios.post(u.get, { body: t });
        if (urls()?.get !== u.get) return; // thread switched mid-send — don't drop it into the wrong thread (next poll shows it in the right one)
        messages.value.push(data);
        scrollDown();
    }

    // Switch threads (DM): reset the buffer so the next poll re-primes cleanly.
    function reset() {
        messages.value = [];
        lastSeen = 0;
        primed = false;
    }

    onMounted(() => { load(); timer = setInterval(() => { if (!document.hidden) load(); }, 3000); });
    onBeforeUnmount(() => timer && clearInterval(timer));

    return { messages, body, scroller, load, send, reset };
}

export function msgTime(at) {
    return new Date(at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
