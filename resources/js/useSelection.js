import { ref, watch, nextTick } from 'vue';

// Shared open/select + map→list reverse-sync for the accordion widgets (Roster, Waypoints).
// `kind` is the selection type ('user' | 'waypoint'), `domPrefix` the card element id prefix,
// `has(id)` reports whether the id is currently in the list. Returns the open-card ref + a toggle.
// `active()` reports whether map↔list sync should run. When this widget is a full-size page there's no
// map on screen to sync with, so callers pass `() => c.focus !== '<their key>'` to switch it all off.
export function useSelectionSync(c, kind, domPrefix, has, scroll = true, active = () => true) {
    const openId = ref(null);

    function toggle(id) {
        openId.value = openId.value === id ? null : id;
        if (openId.value && active()) c.select(kind, id); // highlight on the map when opened
    }

    // selecting on the map opens the matching card here (and scrolls to it unless scroll is off)
    watch(() => c.selection.value, (sel) => {
        if (!active()) return;
        if (sel?.type === kind && sel.id !== openId.value && has(sel.id)) {
            openId.value = sel.id;
            if (scroll) nextTick(() => document.getElementById(domPrefix + sel.id)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
        }
    });

    return { openId, toggle };
}
