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

// "~9 min" / "~1 h 20 min" / "~2 h" — a compact human duration from seconds.
export function fmtDuration(s) {
    if (s == null) return '';
    const min = Math.round(s / 60);
    if (min < 1) return '<1 min';
    if (min < 60) return `~${min} min`;
    const h = Math.floor(min / 60);
    const m = min % 60;
    return m ? `~${h} h ${m} min` : `~${h} h`;
}
