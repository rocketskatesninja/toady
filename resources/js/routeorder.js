import { haversineMeters } from '@/geo';

// Order a set of stops to shorten the walk between them: a nearest-neighbour seed refined by 2-opt.
// Stops are {id, lng, lat}; an optional [lng, lat] `start` anchors the path (e.g. the agent's live GPS).
// Pure and side-effect free — the caller decides which stops are eligible (we never reorder link throws).
export function shortestOrder(stops, start = null) {
    if (stops.length <= 2) return stops.slice();

    const remaining = stops.slice();
    const order = [];
    let cur = start;
    if (!cur) { order.push(remaining.shift()); cur = xy(order[0]); }

    while (remaining.length) {
        let bi = 0;
        let bd = Infinity;
        for (let i = 0; i < remaining.length; i++) {
            const d = haversineMeters(cur, xy(remaining[i]));
            if (d < bd) { bd = d; bi = i; }
        }
        const next = remaining.splice(bi, 1)[0];
        order.push(next);
        cur = xy(next);
    }
    return twoOpt(order, start);
}

const xy = (s) => [s.lng, s.lat];

// total path length, optionally counting the leg from `start` into the first stop
function pathLen(order, start) {
    let total = 0;
    let prev = start;
    for (const s of order) {
        if (prev) total += haversineMeters(prev, xy(s));
        prev = xy(s);
    }
    return total;
}

// classic 2-opt: repeatedly reverse the segment that most shortens the path, until no swap helps
function twoOpt(order, start) {
    let best = order.slice();
    let improved = true;
    let guard = 0;
    while (improved && guard++ < 60) {
        improved = false;
        for (let i = 0; i < best.length - 1; i++) {
            for (let k = i + 1; k < best.length; k++) {
                const cand = best.slice(0, i).concat(best.slice(i, k + 1).reverse(), best.slice(k + 1));
                if (pathLen(cand, start) + 1e-6 < pathLen(best, start)) { best = cand; improved = true; }
            }
        }
    }
    return best;
}
