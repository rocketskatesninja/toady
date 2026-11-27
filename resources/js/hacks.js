// Hack timers, client side: the countdown (the server does the game math and ships it on each timer —
// cooldown_s, hack_limit, window_ends_at; this only places "now" against it), the mod-slot vocabulary, and the
// alert switches.
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { lsGet, lsSet } from '@/ls';
import { pushSubscribed, checkPush } from '@/usePush';

// Mod slots: each holds "type:rarity" (e.g. "heat_sink:rare") or null. Rarity colours are the game's own, as IITC-CE
// defines them (COLORS_MOD: common #49EBC3 teal · rare #B68BFF purple · very rare #F781FF pink), with darker
// shades in daylight mode so they stay readable on white.
export const RARITY = {
    common: { label: 'Common', tone: 'text-[#49ebc3] daylight:text-teal-700 border-[#49ebc3]/60 bg-[#49ebc3]/10' },
    rare: { label: 'Rare', tone: 'text-[#b68bff] daylight:text-violet-700 border-[#b68bff]/60 bg-[#b68bff]/10' },
    very_rare: { label: 'Very rare', tone: 'text-[#f781ff] daylight:text-fuchsia-700 border-[#f781ff]/60 bg-[#f781ff]/10' },
};
export const MOD_TYPES = { heat_sink: 'Heat Sink', multi_hack: 'Multi-hack' };
export function parseMod(key) {
    if (!key) return null;
    const [type, rarity] = key.split(':');
    return { type, rarity, label: `${RARITY[rarity].label} ${MOD_TYPES[type]}` };
}

// New timers start on the portal alignment ("friendly" / "enemy") you picked last, per device.
const FRIENDLY_KEY = 'toady:hack-friendly';
export const lastFriendly = () => lsGet(FRIENDLY_KEY) === '1';
export const rememberFriendly = (friendly) => lsSet(FRIENDLY_KEY, friendly ? '1' : '0');

// Alerts when a timer runs out — two per-user switches in notify_prefs (default on): `hacks` = the in-page toast plus
// a background push (toady:hack-alerts) to every device that turned push on; `hacks_buzz` = whether they vibrate.
// A device with push gets its buzz from the system notification (the only vibration Firefox for Android allows);
// one without falls back to vibrating from the page while it's open.
export function useHackAlerts() {
    if (pushSubscribed.value === null) checkPush();
    const page = usePage();
    const prefs = computed(() => page.props.auth.user?.notify_prefs || {});
    const alerts = computed(() => prefs.value.hacks !== false);
    const buzz = computed(() => prefs.value.hacks_buzz !== false);
    const buzzInPage = computed(() => buzz.value && pushSubscribed.value !== true);
    return { alerts, buzz, buzzInPage, pushSubscribed };
}

/**
 * Where a timer stands at `now` (ms):
 *   ready    — hack away (waitMs 0); windowEndsMs set while an unfinished burnout window is still open
 *   cooldown — waitMs until the next hack (totalMs = the full cooldown, for a progress bar)
 *   burnout  — out of hacks; waitMs until the 4-hour window (from the first hack) closes
 */
export function timerState(t, now) {
    const limit = t.hack_limit;
    const windowEnd = t.window_ends_at ? Date.parse(t.window_ends_at) : null;
    if (!windowEnd || now >= windowEnd) return { phase: 'ready', used: 0, limit, waitMs: 0, windowEndsMs: null };

    const used = t.hacks.length;
    if (used >= limit) return { phase: 'burnout', used, limit, waitMs: windowEnd - now, windowEndsMs: windowEnd };

    const totalMs = t.cooldown_s * 1000;
    const cdEnd = Date.parse(t.hacks[used - 1]) + totalMs;
    if (now < cdEnd) return { phase: 'cooldown', used, limit, waitMs: cdEnd - now, totalMs, windowEndsMs: windowEnd };

    return { phase: 'ready', used, limit, waitMs: 0, windowEndsMs: windowEnd };
}

/** 4:05 under an hour, 3:59:12 above — seconds rounded UP so it never reads 0:00 while still waiting. */
export function fmtWait(ms) {
    let s = Math.max(0, Math.ceil(ms / 1000));
    const h = Math.floor(s / 3600); s -= h * 3600;
    const m = Math.floor(s / 60); s -= m * 60;
    const ss = String(s).padStart(2, '0');
    return h ? `${h}:${String(m).padStart(2, '0')}:${ss}` : `${m}:${ss}`;
}
