// Shared by the Plan widget and its portal cards: the directive vocabulary and the catalog lookup.
// Control styling lives in @/ui.

// actions — the four common ones get one-tap buttons, the rest sit behind "more"
export const COMMON = ['link', 'deploy', 'farm keys', 'hack'];
export const MORE = ['frack', 'capture', 'destroy', 'ada', 'jarvis', 'mod', 'recharge', 'photo', 'passphrase', 'move', 'note'];
export const ACTIONS = [...COMMON, ...MORE];
// actions with a second field (link target / mod / key count) — see ActionDetail
// keys the team holds for one portal, and the total still to farm across a plan
export const keysHeld = (holdings, waypointId) => (holdings || []).filter((h) => h.op_waypoint_id === waypointId).reduce((sum, h) => sum + h.qty, 0);
export const keysShort = (waypoints, holdings) => waypoints.reduce((sum, w) => sum + Math.max(0, (w.keys_needed || 0) - keysHeld(holdings, w.id)), 0);

export const hasDetail = (a) => a === 'link' || a === 'mod' || a === 'farm keys';

// Where the op is: the middle of its placed portals (null until one is placed) — catalog searches rank from here.
export function opCentre(waypoints) {
    const placed = (waypoints || []).filter((w) => w.lat != null && w.lng != null);
    if (!placed.length) return null;
    return { lat: placed.reduce((n, w) => n + w.lat, 0) / placed.length, lng: placed.reduce((n, w) => n + w.lng, 0) / placed.length };
}

// Debounced catalog lookup that writes into a results ref — the add box and a card's "set portal" box. `near()`
// gives the op's whereabouts, so the nearest matches come first and each says how far it is.
export function catalogSearch(queryRef, resultsRef, near = () => null) {
    let timer;
    return () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const q = queryRef.value.trim();
            if (q.length < 2) { resultsRef.value = []; return; }
            const { data } = await window.axios.get('/api/catalog/search', { params: { q, ...near() } });
            resultsRef.value = data;
        }, 200);
    };
}
