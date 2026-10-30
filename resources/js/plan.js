// Shared by the Plan widget and its portal cards: the directive vocabulary and the catalog lookup.
// Control styling lives in @/ui.

// actions — the four common ones get one-tap buttons, the rest sit behind "more"
export const COMMON = ['link', 'deploy', 'farm keys', 'hack'];
export const MORE = ['frack', 'capture', 'destroy', 'ada', 'jarvis', 'mod', 'recharge', 'photo', 'passphrase', 'move', 'note'];
export const ACTIONS = [...COMMON, ...MORE];
// actions with a second field (link target / mod / key count) — see ActionDetail
export const hasDetail = (a) => a === 'link' || a === 'mod' || a === 'farm keys';

// Debounced catalog lookup that writes into a results ref — the add box and a card's "set portal" box.
export function catalogSearch(queryRef, resultsRef) {
    let timer;
    return () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const q = queryRef.value.trim();
            if (q.length < 2) { resultsRef.value = []; return; }
            const { data } = await window.axios.get('/api/catalog/search', { params: { q } });
            resultsRef.value = data;
        }, 200);
    };
}
