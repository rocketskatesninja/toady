import { ref, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Data several components show at once (the header bell, the notifications panel and the chat's unread dots; the
// weather panel and the advisories) is fetched ONCE and shared, however many of them are on screen.

/** A poll shared by its subscribers: it runs at the fastest interval any mounted one asked for, and stops with the last. */
function sharedPoll(load, key) {
    const subs = new Set();
    let timer = null;
    let every = 0;
    let last = { key: undefined, at: 0 };
    const run = () => { last = { key: key(), at: Date.now() }; load(); };
    function retime() {
        const want = subs.size ? Math.min(...[...subs].map((s) => s.ms)) : 0;
        if (want === every) return;
        clearInterval(timer);
        every = want;
        timer = want ? setInterval(run, want) : null;
    }
    return {
        active: () => subs.size > 0,
        use(ms) {
            const me = { ms };
            onMounted(() => {
                subs.add(me);
                retime();
                // panels load a beat apart: the first one to mount fetches, the rest reuse that answer
                if (key() !== last.key || Date.now() - last.at > 3000) run();
            });
            onBeforeUnmount(() => { subs.delete(me); retime(); });
        },
    };
}

const opId = () => usePage().props.op?.id; // the op in view, if any — read per fetch, so a new page is picked up

// --- notifications: the bell's unread count (every op) + the list for the op in view ---
const unread = ref(0);
const items = ref([]);
let seeded = false;
let fetchingFor = null; // the op a request is in flight for — the components mounting together make one request
async function loadNotifications() {
    const op = opId();
    const key = op ?? '';
    if (fetchingFor === key) return;
    fetchingFor = key;
    try {
        const { data } = await window.axios.get('/notifications/feed', { params: { op } });
        if ((opId() ?? '') !== key) return; // moved to another op meanwhile
        unread.value = data.unread;
        items.value = data.items;
    } catch (e) { /* keep last */ } finally {
        if (fetchingFor === key) fetchingFor = null;
    }
}
const notificationPoll = sharedPoll(loadNotifications, opId);
// a backgrounded tab throttles timers, so catch up the moment it's looked at again
document.addEventListener('visibilitychange', () => { if (!document.hidden && notificationPoll.active()) loadNotifications(); });

/** The shared notification feed. `reload` after marking read or clearing, so every subscriber follows at once. */
export function useNotifications(ms) {
    if (!seeded) { unread.value = usePage().props.unreadNotifications ?? 0; seeded = true; }
    notificationPoll.use(ms);
    return { unread, items, reload: loadNotifications };
}

// --- weather for the op in view ---
const wx = ref(null);
const wxLoading = ref(true);
let wxOp = null;
let wxFetching = false;
let wxRetry = null;
async function loadWeather() {
    const op = opId();
    if (!op || wxFetching) return;
    if (wxOp !== op) { wxOp = op; wx.value = null; wxLoading.value = true; }
    clearTimeout(wxRetry);
    // a transient upstream hiccup shouldn't leave the panels blank for the full 10-min gap
    const retry = () => { if (weatherPoll.active()) wxRetry = setTimeout(loadWeather, 30000); };
    wxFetching = true;
    try {
        const { data } = await window.axios.get(`/ops/${op}/weather`);
        if (opId() !== op) return;
        wx.value = data;
        // the region is known (a placed waypoint) but the weather service was momentarily down
        if (data?.ok === false && (usePage().props.waypoints || []).some((w) => w.lat != null)) retry();
    } catch (e) { retry(); } finally {
        wxFetching = false;
        wxLoading.value = false;
    }
}
const weatherPoll = sharedPoll(loadWeather, opId);

/** The shared forecast, refreshed every 10 minutes — weather moves slowly. */
export function useWeather() {
    weatherPoll.use(600000);
    return { wx, loading: wxLoading, reload: loadWeather };
}
