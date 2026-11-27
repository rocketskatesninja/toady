import { ref, watch } from 'vue';

// Shared open/select + map→list reverse-sync for the accordion widgets (Roster, Plan).
// `kind` is the selection type ('user' | 'waypoint'), `has(id)` reports whether the id is currently in the
// list. Returns the open-card ref + a toggle.
// `active()` reports whether map↔list sync should run: a widget open as a full page has no map on screen to
// sync with, so it passes `() => c.focus !== '<its key>'` to switch it all off.
export function useSelectionSync(c, kind, has, active = () => true) {
    const openId = ref(null);

    function toggle(id) {
        openId.value = openId.value === id ? null : id;
        if (openId.value && active()) c.select(kind, id); // highlight on the map when opened
    }

    // selecting on the map opens the matching card here
    watch(() => c.selection.value, (sel) => {
        if (active() && sel?.type === kind && sel.id !== openId.value && has(sel.id)) openId.value = sel.id;
    });

    return { openId, toggle };
}
