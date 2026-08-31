// Compact relative time from an ISO string: "just now" / "5m" / "3h" / "2d".
// `suffix: true` appends " ago"; `now` overrides the under-a-minute label ("now", "just now", …).
export function relTime(at, { suffix = false, now = 'just now' } = {}) {
    if (!at) return '';
    const s = Math.floor((Date.now() - new Date(at).getTime()) / 1000);
    if (s < 60) return now;
    const suf = suffix ? ' ago' : '';
    if (s < 3600) return `${Math.floor(s / 60)}m${suf}`;
    if (s < 86400) return `${Math.floor(s / 3600)}h${suf}`;
    return `${Math.floor(s / 86400)}d${suf}`;
}
