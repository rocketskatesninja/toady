import { ref } from 'vue';

// The one in-app notification queue — real-time events AND server flash messages funnel here (rendered by
// Toaster). Each notification auto-dismisses and fires a short vibrate (if enabled). `tone` colours the card.
const toasts = ref([]);
let nextId = 1;
let vibrateOn = true; // gated by the user's "buzz my phone" preference (set from AppLayout)
export function setNotifyVibrate(on) { vibrateOn = on !== false; }

export function useNotify() {
    // `vibrate` overrides the "buzz my phone" preference for one alert (hack timers carry their own buzz switch)
    function notify(title, body = '', tone = 'default', vibrate = vibrateOn) {
        const id = nextId++;
        toasts.value.push({ id, title, body, tone });
        if (vibrate) { try { navigator.vibrate?.(180); } catch (e) { /* unsupported */ } }
        setTimeout(() => dismiss(id), 6000);
        return id;
    }
    function dismiss(id) {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }
    return { toasts, notify, dismiss };
}
