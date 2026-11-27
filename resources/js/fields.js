// Field + score estimates from the plan's `link` directives. We only know the PLAN (which links exist + which are
// checked off "thrown") — there's no live game feed — so a "completed" field is one whose three links are all done.
// Fields come from the same detector the map shades (fieldsFromLinks: portals matched by position), so the counts
// here always agree with what the map draws.
import { linksFromSteps, fieldsFromLinks } from '@/geo';

const AP_PER = { link: 313, capture: 500, deploy: 125, mod: 125 }; // Ingress AP per single done action
const AP_FIELD = 1250;        // per control field created
// Effective people/km² for the MU estimate. Region-dependent, so it's an admin
// setting (Admin → cycle timing) threaded in as `density`; this is the fallback.
// Default CALIBRATED against a real op: "The Pearl PT 2" scored ~400k MU over
// 1083 km² of summed field area → ~370/km² for coastal-GA fields.
const DEFAULT_MU_DENSITY = 375;

// area of a lat/lng triangle in km² (equirectangular around its centroid — fine at field scale)
function triAreaKm2(coords) {
    const meanLat = (coords[0][1] + coords[1][1] + coords[2][1]) / 3 * Math.PI / 180;
    const mLat = 110574, mLng = 111320 * Math.cos(meanLat);
    const p = coords.map(([lng, lat]) => [lng * mLng, lat * mLat]);
    const area = Math.abs((p[1][0] - p[0][0]) * (p[2][1] - p[0][1]) - (p[2][0] - p[0][0]) * (p[1][1] - p[0][1])) / 2;
    return area / 1e6;
}

/** @returns {{plannedCount:number, completedCount:number, linksPlanned:number, linksDone:number, areaKm2:number, ap:number, mu:number}} */
export function analyzeFields(steps, waypoints, density = DEFAULT_MU_DENSITY) {
    const links = linksFromSteps(steps, waypoints);
    // a physical link counts once, whichever end it's planned from (same portal matching as the fields)
    const edge = (l) => l.coords.map(String).sort().join('|');
    const planned = new Set(links.map(edge));
    const done = new Set(links.filter((l) => l.done).map(edge));

    const fields = fieldsFromLinks(links);
    const completed = fields.filter((f) => f.properties.done);
    const areaKm2 = completed.reduce((s, f) => s + triAreaKm2(f.geometry.coordinates[0]), 0);
    let ap = completed.length * AP_FIELD;
    for (const s of steps || []) if (s.done && AP_PER[s.action]) ap += AP_PER[s.action];

    return {
        plannedCount: fields.length,
        completedCount: completed.length,
        linksPlanned: planned.size,
        linksDone: done.size,
        areaKm2,
        ap,
        mu: Math.round(areaKm2 * (density || DEFAULT_MU_DENSITY)),
    };
}
