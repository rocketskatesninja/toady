import { onBeforeUnmount } from 'vue';

/**
 * Save a box as the agent types — `ms` after the last keystroke — and at once on Enter / blur. A plain
 * @change only fires on Enter or blur, and closing a phone keyboard does neither, so the number never saved.
 * `save(raw)` gets the box's string value. An emptied box waits for Enter / blur (no 0 saved mid-edit).
 * Usage: `const box = useAutosave(save)` → `@input="box.onInput" @change="box.onChange"`.
 */
export function useAutosave(save, ms = 1000) {
    let timer = null;
    let pending = null; // the value typed since the last save, if any

    const flush = () => {
        clearTimeout(timer);
        timer = null;
        if (pending === null) return;
        const v = pending;
        pending = null;
        save(v);
    };

    // never fire a save after the page has moved on — its back() redirect would bounce the agent here
    onBeforeUnmount(() => clearTimeout(timer));

    return {
        onInput(e) {
            pending = e.target.value;
            clearTimeout(timer);
            timer = pending === '' ? null : setTimeout(flush, ms);
        },
        onChange: flush,
    };
}
