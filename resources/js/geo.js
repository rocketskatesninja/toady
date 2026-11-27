// Great-circle distance between two [lng, lat] points, in metres.
export function haversineMeters(a, b) {
    const R = 6371000;
    const dLat = ((b[1] - a[1]) * Math.PI) / 180;
    const dLng = ((b[0] - a[0]) * Math.PI) / 180;
    const lat1 = (a[1] * Math.PI) / 180;
    const lat2 = (b[1] * Math.PI) / 180;
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
    return R * 2 * Math.asin(Math.min(1, Math.sqrt(h)));
}

// Total length of a polyline of [lng, lat] points, in metres.
export function pathMeters(points) {
    let total = 0;
    for (let i = 1; i < points.length; i++) total += haversineMeters(points[i - 1], points[i]);
    return total;
}

// "820 m" / "2.4 km" — a compact human distance from metres.
export function fmtDistance(m) {
    if (m == null) return '';
    return m < 1000 ? `${Math.round(m)} m` : `${(m / 1000).toFixed(m < 10000 ? 1 : 0)} km`;
}

// "~9 min" / "~1 h 20 min" / "~2 h" / "~5 days" — a compact human duration from seconds.
export function fmtDuration(s) {
    if (s == null) return '';
    const min = Math.round(s / 60);
    if (min < 1) return '<1 min';
    if (min < 60) return `~${min} min`;
    const h = Math.floor(min / 60);
    if (h >= 48) return `~${Math.round(h / 24)} days`;
    const m = min % 60;
    return m ? `~${h} h ${m} min` : `~${h} h`;
}

// ---- plan geometry: fields, and where two plans get in each other's way ----
// Links are the op map's shape: [{ coords: [[lng, lat], [lng, lat]], done }]. Portals are matched by position, so
// two plan stops at the same portal count as one.

// The plan's links between placed portals, from its `link` directives (origin waypoint → each target id in
// s.links); done = that directive is checked off. Shared by the map and the progress / mission-complete counts.
export function linksFromSteps(steps, waypoints) {
    const at = {};
    for (const w of waypoints || []) if (w.lat != null && w.lng != null) at[w.id] = [w.lng, w.lat];
    const links = [];
    for (const s of steps || []) {
        if (s.action !== 'link' || !Array.isArray(s.links) || !at[s.op_waypoint_id]) continue;
        for (const to of s.links) if (at[to]) links.push({ coords: [at[s.op_waypoint_id], at[to]], done: !!s.done });
    }
    return links;
}

// Every field a plan's links close (three portals all linked to each other), as GeoJSON polygon features; done =
// all three links thrown. (The game makes at most one field per side of each link, so a few triangles in a deep
// layered plan may never form — close enough.)
export function fieldsFromLinks(links) {
    const adj = new Map(); // "lng,lat" → Map(neighbour "lng,lat" → link thrown?)
    const at = new Map();  // "lng,lat" → [lng, lat]
    const join = (a, b, done) => {
        if (!adj.has(a)) adj.set(a, new Map());
        adj.get(a).set(b, adj.get(a).get(b) || done); // the same link planned from both ends counts once
    };
    for (const seg of links) {
        const [p, q] = seg.coords || seg;
        const a = p.join(), b = q.join();
        if (a === b) continue;
        at.set(a, p); at.set(b, q);
        join(a, b, !!seg.done); join(b, a, !!seg.done);
    }
    const fields = [];
    for (const [a, na] of adj) {
        for (const b of na.keys()) {
            if (b <= a) continue;
            for (const c of adj.get(b).keys()) {
                if (c <= b || !na.has(c)) continue; // a < b < c, so each triangle is found once
                fields.push({
                    type: 'Feature',
                    geometry: { type: 'Polygon', coordinates: [[at.get(a), at.get(b), at.get(c), at.get(a)]] },
                    properties: { done: na.get(b) && na.get(c) && adj.get(b).get(c) },
                });
            }
        }
    }
    return fields;
}

// How deep fields stack at their most layered point (sampled at each field's centre), so shading can be scaled
// to it: a lone field gets a clear tint, and an 18-layer stack still lets the map show through. 1 for no fields.
export function stackDepth(fields) {
    const side = (o, a, b) => (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0]);
    const inside = (p, [a, b, c]) => { const d = [side(a, b, p), side(b, c, p), side(c, a, p)]; return d.every((x) => x >= 0) || d.every((x) => x <= 0); };
    const tris = fields.map((f) => f.geometry.coordinates[0].slice(0, 3));
    return tris.reduce((max, t) => {
        const mid = [0, 1].map((i) => (t[0][i] + t[1][i] + t[2][i]) / 3);
        return Math.max(max, tris.filter((u) => inside(mid, u)).length);
    }, 1);
}

const SAME_PORTAL_M = 5; // two plan portals this close are the same portal

// Where another plan gets in this one's way. `mine` / `theirs` = { links, portals: [{ lng, lat, title }] }.
// - crossings: a link of theirs crossing a link of mine — the game won't let a link cross an existing one, so
//   whichever op throws second fails. Links that only meet at a shared portal don't count.
// - shared: a portal in both plans (competing for keys, agents, and timing).
// - underFields: a portal that throws or takes a link in one plan, inside a field of the other — a portal under a
//   field can't link out. `whose` = which plan the portal belongs to.
// Planar maths in a local projection: fine for links up to tens of km. The game's links are great circles, so a
// near-miss on a 100 km+ link could be judged wrong.
export function crossovers(mine, theirs) {
    const pts = [...mine.links, ...theirs.links].flatMap((l) => l.coords);
    if (!pts.length && !mine.portals.length) return { crossings: [], shared: [], underFields: [] };
    const lat0 = pts.length ? pts.reduce((s, p) => s + p[1], 0) / pts.length : mine.portals[0].lat;
    const kx = Math.cos((lat0 * Math.PI) / 180); // squash longitude so the projection is locally true to scale
    const xy = ([lng, lat]) => [lng * kx, lat];
    const side = (o, a, b) => (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0]);
    const same = (p, q) => haversineMeters(p, q) <= SAME_PORTAL_M;
    const titleAt = (portals, p) => portals.find((w) => same([w.lng, w.lat], p))?.title || 'Portal';

    const crossings = [];
    for (const m of mine.links) {
        const [a, b] = m.coords;
        for (const t of theirs.links) {
            const [c, d] = t.coords;
            if (same(a, c) || same(a, d) || same(b, c) || same(b, d)) continue; // meet at a portal, not a crossing
            const [A, B, C, D] = [a, b, c, d].map(xy);
            const d1 = side(C, D, A), d2 = side(C, D, B), d3 = side(A, B, C), d4 = side(A, B, D);
            if (!(d1 * d2 < 0 && d3 * d4 < 0)) continue; // strictly on both sides of each other = a proper crossing
            const u = d1 / (d1 - d2); // how far along mine the crossing sits
            crossings.push({
                at: [(A[0] + u * (B[0] - A[0])) / kx, A[1] + u * (B[1] - A[1])],
                mine: `${titleAt(mine.portals, a)} → ${titleAt(mine.portals, b)}`,
                theirs: `${titleAt(theirs.portals, c)} → ${titleAt(theirs.portals, d)}`,
            });
        }
    }

    const shared = mine.portals
        .filter((p) => theirs.portals.some((q) => same([p.lng, p.lat], [q.lng, q.lat])))
        .map((p) => ({ at: [p.lng, p.lat], title: p.title }));

    // linked portals of one plan strictly inside a field of the other (on an edge or at a corner doesn't count)
    const linked = (plan) => {
        const out = [];
        for (const p of plan.links.flatMap((l) => l.coords)) if (!out.some((q) => same(p, q))) out.push(p);
        return out;
    };
    const inside = (p, tri) => {
        const [P, A, B, C] = [p, ...tri].map(xy);
        const s = [side(A, B, P), side(B, C, P), side(C, A, P)];
        return (s.every((v) => v > 0) || s.every((v) => v < 0)) && !tri.some((v) => same(v, p));
    };
    const underFields = [];
    for (const [plan, other, whose] of [[theirs, mine, 'theirs'], [mine, theirs, 'mine']]) {
        const tris = fieldsFromLinks(other.links).map((f) => f.geometry.coordinates[0].slice(0, 3));
        for (const p of linked(plan)) {
            if (tris.some((t) => inside(p, t))) underFields.push({ at: p, title: titleAt(plan.portals, p), whose });
        }
    }
    return { crossings, shared, underFields };
}
