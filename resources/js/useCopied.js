import { ref } from 'vue';

// Copy text to the clipboard and flash `copied` for a moment — only when the copy really happened, so a blocked
// clipboard never shows "copied". `text` may be a promise (e.g. fetched first).
export function useCopied(ms = 1600) {
    const copied = ref(false);
    let timer;
    async function copy(text) {
        try {
            await navigator.clipboard.writeText(await text);
            copied.value = true;
            clearTimeout(timer);
            timer = setTimeout(() => { copied.value = false; }, ms);
        } catch (e) { /* clipboard blocked, or the text couldn't be fetched */ }
    }
    return { copied, copy };
}
