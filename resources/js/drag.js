// Drag config for the two reorder surfaces:
//   • the dashboard widget grid — grid-layout-plus / interact.js (drags from its header handle)
//   • the mission directive list — vuedraggable / SortableJS (press-and-hold)
// One place to tune it and to keep the page from scrolling mid-drag.

// How long you hold (still) before a MISSION CARD starts to drag (SortableJS uses this directly).
export const HOLD_MS = 500;

// Coarse pointer = touch-primary device. On touch we need a short hold before a header drag starts, so a
// swipe on a panel header scrolls the page instead of instantly dragging; on desktop we drag immediately
// (a mouse on a dedicated handle is unambiguous, and a hold there was the source of the flaky "won't drag
// after a resize" bug — so it stays touch-only).
const IS_TOUCH = typeof window !== 'undefined' && !!window.matchMedia?.('(pointer: coarse)')?.matches;
export const GRID_HOLD_MS = 300;

// Dashboard grid: drag from the `.widget-drag` header handle. `hold` is applied ONLY on touch (see above);
// interact.js cancels a pending hold if the finger moves past its tolerance, so a swipe scrolls the page
// and only a still press-hold arms the drag. autoScroll pans the window when a drag/resize nears a viewport
// edge (interact's bundled plugin); margin 80 clears the 56px sticky header so it scrolls before a widget
// slides under it.
export const GRID_DRAG_OPTION = { hold: IS_TOUCH ? GRID_HOLD_MS : 0, autoScroll: { enabled: true, margin: 80, speed: 600 } };
export const GRID_RESIZE_OPTION = { autoScroll: { enabled: true, margin: 80, speed: 600 } };

// We leave `touch-action: auto` on the widgets so a quick swipe scrolls the page natively (with
// momentum). The only time that's wrong is while a drag is in progress — so this one non-passive
// listener cancels the scroll then, and only then. Installed once for the app's lifetime.
//   .vgl-item--dragging → a dashboard widget is being dragged
//   .sortable-chosen    → a mission card is being dragged
let installed = false;
export function installDragScrollGuard() {
    if (installed || typeof document === 'undefined') return;
    installed = true;
    document.addEventListener('touchmove', (e) => {
        if (document.getElementsByClassName('vgl-item--dragging').length
            || document.getElementsByClassName('sortable-chosen').length) {
            e.preventDefault();
        }
    }, { passive: false });
}

// A short haptic pulse the moment a touch drag arms — the finger held a header still for GRID_HOLD_MS
// (same delay as interact's touch `hold`), so the drag is now live. The pulse MUST be fired from inside a
// `touchmove` (a user gesture): Android Chrome silently ignores navigator.vibrate() called from a detached
// timer. A resting finger still emits micro-move events, so it lands ~GRID_HOLD_MS in; a real scroll (moved
// far before the hold elapsed) cancels it. Delegated + installed once, mirroring installDragScrollGuard.
// No-ops on desktop and where Vibration is unsupported (notably iOS Safari — the hold still works there).
let hapticInstalled = false;
export function installDragHaptic() {
    if (hapticInstalled || typeof document === 'undefined') return;
    if (!IS_TOUCH || typeof navigator === 'undefined' || !navigator.vibrate) return;
    hapticInstalled = true;
    let active = false;
    let buzzed = false;
    let t0 = 0;
    let x0 = 0;
    let y0 = 0;
    document.addEventListener('touchstart', (e) => {
        const handle = e.target?.closest?.('.widget-drag');
        active = !! handle && ! e.target.closest('button, a'); // buttons/links aren't drag starts
        buzzed = false;
        if (active) { const p = e.touches[0]; t0 = Date.now(); x0 = p.clientX; y0 = p.clientY; }
    }, { passive: true });
    document.addEventListener('touchmove', (e) => {
        if (! active || buzzed) return;
        const p = e.touches[0];
        if (! p) return;
        const far = Math.abs(p.clientX - x0) > 14 || Math.abs(p.clientY - y0) > 14;
        if (far && Date.now() - t0 < GRID_HOLD_MS) { active = false; return; } // moved before the hold → a scroll
        if (Date.now() - t0 >= GRID_HOLD_MS) { buzzed = true; try { navigator.vibrate(15); } catch (err) { /* ignore */ } }
    }, { passive: true });
    const end = () => { active = false; };
    document.addEventListener('touchend', end, { passive: true });
    document.addEventListener('touchcancel', end, { passive: true });
}
