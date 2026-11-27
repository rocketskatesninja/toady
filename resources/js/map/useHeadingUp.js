import { ref } from 'vue';
import { mapButton } from '@/map/draw';

// Heading-up: rotate the map to the device's compass heading (opt-in, mobile only). NOT persisted — a fresh tap
// re-arms the iOS sensor permission. `ctx.map` is the map; `locked` is the map-lock toggle (a locked map can't rotate).
export function useHeadingUp(ctx, locked) {
    const headingUp = ref(false);
    const compassState = ref('off'); // off | waiting | live | nodata — drives the button colour + title + hint
    let orientationHandler = null;
    let bearingRAF = 0;
    let lastBearing = 0;
    let gotAbsolute = false;
    let waitTimer = null;
    let touching = false; // fingers on the map — pause heading-up so the compass can't fight pan/pinch gestures
    const onTouch = (e) => { touching = e.touches.length > 0; }; // e.touches = fingers still down (multi-touch safe)

    // A device-orientation event → compass heading (deg clockwise from north), corrected for screen rotation.
    // iOS exposes webkitCompassHeading directly; others use alpha (counter-clockwise from north).
    function headingFromEvent(e) {
        let h;
        if (typeof e.webkitCompassHeading === 'number') h = e.webkitCompassHeading;
        else if (typeof e.alpha === 'number') h = 360 - e.alpha;
        else return null;
        const screenAngle = (typeof screen !== 'undefined' && screen.orientation && screen.orientation.angle) || 0;
        return (h + screenAngle + 360) % 360;
    }

    function onOrientation(e) {
        const absolute = e.absolute === true || e.type === 'deviceorientationabsolute' || typeof e.webkitCompassHeading === 'number';
        if (absolute) gotAbsolute = true;
        else if (gotAbsolute) return; // once a true compass is flowing, ignore the drifting relative stream
        const h = headingFromEvent(e);
        if (h == null) return;
        compassState.value = 'live';
        // low-pass toward the new heading, shortest way round the circle, so the map glides instead of jittering
        const diff = ((h - lastBearing + 540) % 360) - 180;
        lastBearing = (lastBearing + diff * 0.2 + 360) % 360;
        if (!bearingRAF) bearingRAF = requestAnimationFrame(() => { bearingRAF = 0; if (ctx.map && headingUp.value && !touching) ctx.map.setBearing(lastBearing); });
    }

    function enableHeadingUp() {
        const begin = () => {
            if (locked.value) locked.value = false;  // a locked map can't rotate
            ctx.map?.dragRotate?.disable?.();             // the sensor drives rotation — no manual fighting
            gotAbsolute = false;
            orientationHandler = onOrientation;
            // listen to BOTH: the absolute (true-north) stream when the device has it, plus the plain event as a fallback
            window.addEventListener('deviceorientationabsolute', orientationHandler, true);
            window.addEventListener('deviceorientation', orientationHandler, true);
            headingUp.value = true;
            compassState.value = 'waiting';
            clearTimeout(waitTimer);
            waitTimer = setTimeout(() => { if (compassState.value === 'waiting') compassState.value = 'nodata'; }, 3000);
        };
        // iOS 13+ requires an in-gesture permission prompt; everything else can just start listening
        const req = window.DeviceOrientationEvent && DeviceOrientationEvent.requestPermission;
        if (typeof req === 'function') DeviceOrientationEvent.requestPermission().then((s) => { if (s === 'granted') begin(); else compassState.value = 'nodata'; }).catch(() => { compassState.value = 'nodata'; });
        else begin();
    }

    // stop listening to the compass (heading-up off, or the map going away)
    function stopOrientation() {
        clearTimeout(waitTimer);
        if (orientationHandler) {
            window.removeEventListener('deviceorientationabsolute', orientationHandler, true);
            window.removeEventListener('deviceorientation', orientationHandler, true);
            orientationHandler = null;
        }
        if (bearingRAF) { cancelAnimationFrame(bearingRAF); bearingRAF = 0; }
    }
    function disableHeadingUp() {
        stopOrientation();
        headingUp.value = false;
        compassState.value = 'off';
        lastBearing = 0; gotAbsolute = false;
        if (!locked.value) ctx.map?.dragRotate?.enable?.();
        ctx.map?.easeTo?.({ bearing: 0, duration: 300 }); // settle back to north-up
    }

    // styled like the other map toggles, but its click runs enable/disable directly so the iOS permission
    // request stays inside the user gesture (a watcher would defer it out of the gesture and fail on iOS)
    const compassControl = mapButton(
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>',
        () => { headingUp.value ? disableHeadingUp() : enableHeadingUp(); },
        compassState,
        (b) => {
            const s = compassState.value;
            b.style.color = s === 'live' ? 'var(--color-accent)' : s === 'waiting' ? '#fbbf24' : s === 'nodata' ? '#fb7185' : 'var(--color-ink-faint)';
            b.title = s === 'live' ? 'Heading-up: on (tap for north-up)'
                : s === 'waiting' ? 'Heading-up: waiting for compass…'
                    : s === 'nodata' ? 'No compass data — your browser may be blocking motion sensors (Brave Shields / private mode / iOS Motion access)'
                        : 'Rotate the map to your compass heading';
        },
    );

    return { headingUp, compassState, compassControl, onTouch, stopOrientation, disableHeadingUp };
}
