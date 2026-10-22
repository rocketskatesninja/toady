import { ref, onMounted, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';

/** Run fn once on mount, then on an interval; auto-clears on unmount. */
export function usePoll(fn, intervalMs) {
    let timer = null;
    onMounted(() => { fn(); timer = setInterval(fn, intervalMs); });
    onBeforeUnmount(() => timer && clearInterval(timer));
}

/** Refetch the given Inertia props every ~3s while the tab is visible (no WebSockets). */
export function useLivePoll(only, intervalMs = 3000) {
    let timer = null;
    let navigating = false;
    let offStart, offFinish;
    onMounted(() => {
        // router.reload() is itself a visit, and Inertia allows only one at a time — if a poll tick fires
        // mid-navigation it CANCELS the user's visit and reloads the current (old) URL, bouncing them back
        // to the dashboard. So skip the tick while any visit is in flight.
        offStart = router.on('start', () => { navigating = true; });
        offFinish = router.on('finish', () => { navigating = false; });
        timer = setInterval(() => {
            if (document.hidden || navigating) return;
            router.reload({ only, preserveScroll: true, preserveState: true });
        }, intervalMs);
    });
    onBeforeUnmount(() => { if (timer) clearInterval(timer); offStart?.(); offFinish?.(); });
}

/**
 * Opt-in GPS sharing for an op. Uses watchPosition with forced-fresh, high-accuracy fixes so the
 * receiver keeps refining (the first fix is coarse, then tightens over a few seconds as satellites
 * lock) instead of a single possibly-cached read. The newest fix is posted on a steady ~10s cadence,
 * decoupled from how fast the GPS streams updates.
 */
export function usePresenceShare(opId, initial = false) {
    const sharing = ref(!!initial);
    let watchId = null;
    let postTimer = null;
    let last = null;       // most recent fix from the watch
    let sentFirst = false; // push the first good fix immediately, then let the interval take over

    function send() {
        if (!last) return; // nothing to post until the first fix lands
        window.axios.post(`/ops/${opId}/presence`, {
            sharing: true, lat: last.lat, lng: last.lng, accuracy: last.accuracy,
        }).catch(() => {});
    }

    function stop() {
        if (watchId != null && navigator.geolocation) { navigator.geolocation.clearWatch(watchId); }
        watchId = null;
        if (postTimer) { clearInterval(postTimer); postTimer = null; }
    }

    function start() {
        stop();
        sentFirst = false;
        if (navigator.geolocation) {
            // maximumAge:0 forbids a stale/cached (often coarse) fix; watchPosition keeps the GPS live so
            // accuracy improves over the first few seconds and tracks the agent as they move.
            watchId = navigator.geolocation.watchPosition(
                (pos) => {
                    last = { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: Math.round(pos.coords.accuracy || 0) };
                    if (!sentFirst) { sentFirst = true; send(); }
                },
                () => {}, // ignore transient fix errors — the watch keeps trying
                { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 },
            );
        }
        postTimer = setInterval(send, 10000); // network cadence, independent of the GPS refresh rate
    }

    function toggle() {
        sharing.value = !sharing.value;
        if (sharing.value) start();
        else { stop(); last = null; window.axios.post(`/ops/${opId}/presence`, { sharing: false }).catch(() => {}); }
    }

    // If the page loaded already sharing (carried over from before), resume — otherwise the indicator
    // stays "on" but nothing is sent and your position goes stale.
    if (sharing.value) start();
    // Re-send the latest fix the moment the tab returns to the foreground (background tabs are throttled).
    const onVisible = () => { if (sharing.value && document.visibilityState === 'visible') send(); };
    document.addEventListener('visibilitychange', onVisible);

    onBeforeUnmount(() => { stop(); document.removeEventListener('visibilitychange', onVisible); });
    return { sharing, toggle };
}
