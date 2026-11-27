import { loadRadarMeta, radarTiles } from '@/maps';

// RainViewer radar on a MapLibre map (the op map's radar filter and the Weather panel's radar). Each recent frame is
// preloaded as its own raster layer — tiles are fetched once and cached — and the sweep is animated by fading
// opacity between the loaded layers. No re-fetching → no rate-limiting.
// `ctx.map` is the map (null until it exists); `before()` names the layer the radar goes beneath, if any.
export function useRadar(ctx, before = () => undefined) {
    let host = null;
    let frames = []; // recent past frames (paths)
    let layers = []; // one layer id per frame
    let frame = 0;
    let timer = null;

    /** Fetch the latest frame list; keeps the current one when the fetch comes back empty. True when it got frames. */
    async function load() {
        const meta = await loadRadarMeta();
        if (!meta.frames.length) return false;
        host = meta.host;
        frames = meta.frames;
        return true;
    }
    function apply() {
        const map = ctx.map;
        if (!map || !frames.length || layers.length) return;
        const under = before();
        frames.forEach((path, i) => {
            const id = `radar-${i}`;
            // RainViewer serves real radar only through z7; z8+ returns a "Zoom level not supported" placeholder
            // tile. Cap maxzoom so MapLibre overzooms the z7 tile past that instead of fetching (and getting stuck
            // showing) the placeholder — otherwise the warning lingers even after zooming back out.
            map.addSource(id, { type: 'raster', tiles: radarTiles(host, path), tileSize: 256, maxzoom: 7, attribution: 'RainViewer' });
            map.addLayer({
                id, type: 'raster', source: id,
                paint: { 'raster-opacity': i === frames.length - 1 ? 0.6 : 0, 'raster-fade-duration': 0 },
            }, under);
            layers.push(id);
        });
        frame = frames.length - 1;
        if (layers.length > 1) {
            timer = setInterval(() => {
                const prev = frame;
                frame = (frame + 1) % layers.length;
                if (map.getLayer(layers[prev])) map.setPaintProperty(layers[prev], 'raster-opacity', 0);
                if (map.getLayer(layers[frame])) map.setPaintProperty(layers[frame], 'raster-opacity', 0.6);
            }, 600);
        }
    }
    function teardown(removeFromMap) {
        if (timer) { clearInterval(timer); timer = null; }
        const map = ctx.map;
        if (removeFromMap && map) layers.forEach((id) => { if (map.getLayer(id)) map.removeLayer(id); if (map.getSource(id)) map.removeSource(id); });
        layers = [];
    }
    return {
        load,
        apply,
        remove: () => teardown(true),
        forget: () => teardown(false), // after a basemap swap the layers are already gone — just reset
        firstLayer: () => layers[0],
    };
}
