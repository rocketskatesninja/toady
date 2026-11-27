// MapLibre GL 6 (ESM-only). Import it from here, not from 'maplibre-gl', so the worker is wired up.
// Under a bundler MapLibre can't find its own worker file, so Vite builds it as a same-origin asset
// and we hand over the URL once (CSP worker-src 'self' covers it).
import * as maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

maplibregl.setWorkerUrl(workerUrl);

export default maplibregl;
