// Field detection from `link` directives: a field is a triangle of three mutually-linked portals.
// We only know the PLAN (which links exist + which are checked off "thrown") — there's no live game
// feed — so "completed" means all three of a triangle's links are done.

const AP_PER = { link: 313, capture: 500, deploy: 125, mod: 125 }; // Ingress AP per single done action
const AP_FIELD = 1250;        // per control field created
// Effective people/km² for the MU estimate. Region-dependent, so it's an admin
// setting (Admin → cycle timing) threaded in as `density`; this is the fallback.
// Default CALIBRATED against a real op: "The Pearl PT 2" scored ~400k MU over
// 1083 km² of summed field area → ~370/km² for coastal-GA fields.
export const DEFAULT_MU_DENSITY = 375;

// area of a lat/lng triangle in km² (equirectangular around its centroid — fine at field scale)
function triAreaKm2(coords) {
    const meanLat = (coords[0][1] + coords[1][1] + coords[2][1]) / 3 * Math.PI / 180;
    const mLat = 110574, mLng = 111320 * Math.cos(meanLat);
    const p = coords.map(([lng, lat]) => [lng * mLng, lat * mLat]);
    const area = Math.abs((p[1][0] - p[0][0]) * (p[2][1] - p[0][1]) - (p[2][0] - p[0][0]) * (p[1][1] - p[0][1])) / 2;
    return area / 1e6;
}

/**
 * @returns {{
 *   planned: Array<{ids:number[], coords:number[][]}>,
 *   completed: Array<{ids:number[], coords:number[][]}>,
 *   plannedCount:number, completedCount:number, linksPlanned:number, linksDone:number, areaKm2:number, ap:number, mu:number
 * }}
 */
export function analyzeFields(steps, waypoints, density = DEFAULT_MU_DENSITY) {
    const placed = {};
    for (const w of waypoints || []) if (w.lat != null) placed[w.id] = w;

    const key = (a, b) => (a < b ? `${a}-${b}` : `${b}-${a}`);
    const plannedEdges = new Set();
    const doneEdges = new Set();
    for (const s of steps || []) {
        if (s.action !== 'link' || !Array.isArray(s.links)) continue;
        for (const to of s.links) {
            if (!placed[s.op_waypoint_id] || !placed[to]) continue;
            plannedEdges.add(key(s.op_waypoint_id, to));
            if (s.done) doneEdges.add(key(s.op_waypoint_id, to));
        }
    }

    const ids = Object.keys(placed).map(Number);
    const planned = [];
    const completed = [];
    for (let i = 0; i < ids.length; i++) {
        for (let j = i + 1; j < ids.length; j++) {
            if (!plannedEdges.has(key(ids[i], ids[j]))) continue;
            for (let k = j + 1; k < ids.length; k++) {
                const tri = [ids[i], ids[j], ids[k]];
                if (!plannedEdges.has(key(tri[1], tri[2])) || !plannedEdges.has(key(tri[0], tri[2]))) continue;
                const coords = tri.map((id) => [placed[id].lng, placed[id].lat]);
                planned.push({ ids: tri, coords });
                if (doneEdges.has(key(tri[0], tri[1])) && doneEdges.has(key(tri[1], tri[2])) && doneEdges.has(key(tri[0], tri[2]))) {
                    completed.push({ ids: tri, coords });
                }
            }
        }
    }

    const areaKm2 = completed.reduce((s, t) => s + triAreaKm2(t.coords), 0);
    let ap = completed.length * AP_FIELD;
    for (const s of steps || []) if (s.done && AP_PER[s.action]) ap += AP_PER[s.action];

    return {
        planned, completed,
        plannedCount: planned.length,
        completedCount: completed.length,
        linksPlanned: plannedEdges.size,
        linksDone: doneEdges.size,
        areaKm2,
        ap,
        mu: Math.round(areaKm2 * (density || DEFAULT_MU_DENSITY)),
    };
}
