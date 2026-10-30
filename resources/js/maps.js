// Shared MapLibre config + RainViewer radar helpers (used by OpMap + WeatherRadar).

// Keyless vector basemaps; daylight gets a legible light style.
export const BASEMAP = {
    night: 'https://tiles.openfreemap.org/styles/dark',
    day: 'https://tiles.openfreemap.org/styles/liberty',
};
export const SATELLITE_TILES = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
// Esri transparent reference overlays for "hybrid" (satellite + streets): roads, then place labels on top.
export const HYBRID_ROAD_TILES = 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}';
export const HYBRID_LABEL_TILES = 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}';

export function mapTheme() {
    return document.documentElement.classList.contains('daylight') ? 'day' : 'night';
}

// RainViewer: fetch the recent radar frame paths + tile host. Returns { host, frames } ({host:null,frames:[]} on failure).
export async function loadRadarMeta() {
    try {
        const r = await fetch('https://api.rainviewer.com/public/weather-maps.json');
        const j = await r.json();
        return { host: j.host, frames: (j.radar?.past ?? []).slice(-6).map((f) => f.path) };
    } catch (e) {
        return { host: null, frames: [] };
    }
}

export function radarTiles(host, path) {
    return [`${host}${path}/256/{z}/{x}/{y}/4/1_1.png`];
}
