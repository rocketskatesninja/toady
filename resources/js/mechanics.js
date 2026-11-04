// Mirror of a few constants in app/Support/Mechanics.php — stable Ingress game values (Niantic hasn't
// changed them in years). Kept here so range checks and labels stay client-side; if the PHP source ever
// changes, update both.
const LINK_RANGE_BASE_M = 160;
const SOLO_MAX_AVG_RESO = 5.625; // the 8 solo-deploy caps average to this → a solo "L5" portal

// Link range (metres) a portal can throw at a given average resonator level: 160 × level⁴.
const linkRangeMeters = (avgReso) => LINK_RANGE_BASE_M * avgReso ** 4;

// Farthest a solo agent can throw (avg reso 5.625) ≈ 160 km; a fully-built L8 portal ≈ 655 km.
export const soloMaxLinkMeters = () => linkRangeMeters(SOLO_MAX_AVG_RESO);
export const l8MaxLinkMeters = () => linkRangeMeters(8);

// Base hack cooldown (minutes) by portal alignment — Mechanics::COOLDOWN_OWN_MIN / COOLDOWN_ENEMY_MIN.
export const COOLDOWN_FRIENDLY_MIN = 3;
export const COOLDOWN_ENEMY_MIN = 5;
