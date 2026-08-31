// Tiny localStorage wrappers that no-op when storage is unavailable (private mode / blocked), so callers
// don't repeat try/catch everywhere. lsGet returns the raw string (or fallback); lsJSON parses JSON.
export function lsGet(key, fallback = null) {
    try { const v = localStorage.getItem(key); return v === null ? fallback : v; } catch (e) { return fallback; }
}
export function lsSet(key, val) {
    try { localStorage.setItem(key, val); } catch (e) { /* storage unavailable */ }
}
export function lsJSON(key, fallback = null) {
    try { return JSON.parse(localStorage.getItem(key)) ?? fallback; } catch (e) { return fallback; }
}
