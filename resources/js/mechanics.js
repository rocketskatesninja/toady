// Mirror of the link-range constants in app/Support/Mechanics.php — stable Ingress game values
// (Niantic hasn't changed them in years). Kept here so range checks stay client-side; if the PHP
// source ever changes, update both.
export const LINK_RANGE_BASE_M = 160;
export const SOLO_MAX_AVG_RESO = 5.625; // the 8 solo-deploy caps average to this → a solo "L5" portal

// Link range (metres) a portal can throw at a given average resonator level: 160 × level⁴.
export const linkRangeMeters = (avgReso) => LINK_RANGE_BASE_M * avgReso ** 4;

// Farthest a solo agent can throw (avg reso 5.625) ≈ 160 km; a fully-built L8 portal ≈ 655 km.
export const soloMaxLinkMeters = () => linkRangeMeters(SOLO_MAX_AVG_RESO);
export const l8MaxLinkMeters = () => linkRangeMeters(8);

// The average resonator level a portal must reach to throw `metres`.
export const requiredAvgReso = (metres) => (metres / LINK_RANGE_BASE_M) ** 0.25;

// ── Key farming (also mirrors Mechanics.php) ──
// Keys drop when you hack a portal, and portals are generous — the headline drop rate is 75%
// (KEY_DROP_RATE on the Reference page). Glyph-hacking a friendly portal is close to a key every hack (the
// optimistic end); the 75% rate is the conservative end.
export const KEY_DROP_RATE = 0.75;
export const HACKS_BEFORE_BURNOUT = 4;  // a portal burns out after this many hacks…
export const BURNOUT_HOURS = 4;         // …then locks for this long (unless Multi-hack raises the cap)
export const HACK_COOLDOWN_MIN = 5;     // standard between-hack cooldown; a Heat Sink cuts it up to 70%

// Hacks to farm `keys`, optimistic→conservative: ~1 key/hack best case (glyphing a friendly portal) up to
// the 75% headline rate. So a 2-key portal is ~2–3 hacks, not 3–4.
export function hacksForKeys(keys) {
    return { lo: keys, hi: Math.ceil(keys / KEY_DROP_RATE) };
}
// How many burnout windows `hacks` spans (4 hacks each) — >1 means you can't grind it in one sitting
// without Multi-hack mods, repeat visits, or splitting it across agents.
export const burnoutsFor = (hacks) => Math.ceil(hacks / HACKS_BEFORE_BURNOUT);

export const HACK_BURST_MIN = 1.5; // practical minutes per hack when bursting a friendly / multi-hacked farm portal
// Realistic farm time: within a burnout window (≤4 hacks) a farm portal is multi-hacked in a quick burst;
// the real time sink is the 4-hour BURNOUT between windows. (The old estimate charged a full 5-min cooldown
// per hack — right for a lone unmodded portal, but far too slow for actual key farming.)
export function farmMinutes(hacks) {
    const windows = Math.max(1, Math.ceil(hacks / HACKS_BEFORE_BURNOUT));
    return hacks * HACK_BURST_MIN + (windows - 1) * BURNOUT_HOURS * 60;
}
