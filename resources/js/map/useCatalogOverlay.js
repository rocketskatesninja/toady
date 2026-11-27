import { ref, computed, watch } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import maplibregl from '@/maplibre';
import { lsGet, lsSet } from '@/ls';
import { accentColor, featureCollection, point, showTitle, hideTitle } from '@/map/draw';

// The op map's catalog overlay (planning only): the verified portals in view, as dots — tap one to add it to the plan.
// `ctx` is the map component's shared context: { map, props, emit, k, placed, raiseLines, beforeRoute }.
export function useCatalogOverlay(ctx) {
    const isOwner = computed(() => !!usePage().props.auth?.user?.is_owner); // the /catalog page is owner-only
    const portalsOn = ref(ctx.props.editable && lsGet(ctx.k('toady-map-portals')) === '1');
    const skipConfirm = ref(lsGet('toady-catalog-skip') === '1'); // user pref: skip the "add to plan" confirm popup
    watch(portalsOn, (on) => { lsSet(ctx.k('toady-map-portals'), on ? '1' : ''); on ? loadCatalog() : clearCatalog(); });
    watch(skipConfirm, (v) => lsSet('toady-catalog-skip', v ? '1' : ''));

    const CATALOG_MINZOOM = 12;     // below this a whole region would flood the cap — show a hint instead of a wall of dots
    const catalogHint = ref(false); // "zoom in to load portals" (overlay on, but zoomed too far out)
    let catalogReq = 0;             // guards against out-of-order fetches
    let catalogTimer = null;        // debounces pan/zoom refetches
    let catalogPopup = null;
    let catalogBound = false;       // map.on('click', <layer>) handlers survive setStyle → bind exactly once

    function setCatalogData(fc) {
        if (!ctx.map) return;
        if (ctx.map.getSource('catalog')) {
            ctx.map.getSource('catalog').setData(fc);
        } else {
            ctx.map.addSource('catalog', { type: 'geojson', data: fc });
            ctx.map.addLayer({
                id: 'catalog-portals', type: 'circle', source: 'catalog',
                // the viewer's faction colour (ENL green / RES blue), re-read whenever the layer rebuilds (e.g. day/night swap)
                paint: { 'circle-radius': 5, 'circle-color': accentColor(), 'circle-opacity': 0.9, 'circle-stroke-width': 1.5, 'circle-stroke-color': '#04121a' },
            }, ctx.beforeRoute());
            if (!catalogBound) { // bind once; handlers persist across setStyle even though the layer is rebuilt
                ctx.map.on('click', 'catalog-portals', onCatalogClick);
                ctx.map.on('mousemove', 'catalog-portals', showTitle); // hover → name tooltip, like the plan markers
                ctx.map.on('mouseleave', 'catalog-portals', hideTitle);
                catalogBound = true;
            }
        }
        if (ctx.map.getLayer('catalog-portals')) ctx.map.setLayoutProperty('catalog-portals', 'visibility', 'visible');
        ctx.raiseLines(); // keep plan links + route above the catalog dots
    }

    function clearCatalog() {
        catalogHint.value = false;
        catalogPopup?.remove();
        if (ctx.map) hideTitle(ctx.map);
        if (ctx.map?.getLayer('catalog-portals')) ctx.map.setLayoutProperty('catalog-portals', 'visibility', 'none');
    }

    async function loadCatalog() {
        if (!portalsOn.value || !ctx.map) return;
        if (ctx.map.getZoom() < CATALOG_MINZOOM) { // too far out — don't flood; hint to zoom in
            catalogHint.value = true;
            if (ctx.map.getSource('catalog')) ctx.map.getSource('catalog').setData(featureCollection([]));
            return;
        }
        catalogHint.value = false;
        const b = ctx.map.getBounds();
        const myReq = ++catalogReq;
        try {
            const { data } = await window.axios.get('/api/catalog/in-view', { params: { n: b.getNorth(), s: b.getSouth(), e: b.getEast(), w: b.getWest() } });
            if (myReq !== catalogReq || !portalsOn.value) return; // superseded or toggled off mid-flight
            // don't offer portals already in the plan — matched by position (a plan portal added from the catalog sits on
            // its exact coordinates; the op page's portals carry no catalog GUID to match on)
            const spot = (lat, lng) => `${(+lat).toFixed(5)},${(+lng).toFixed(5)}`;
            const have = new Set(ctx.placed().map((w) => spot(w.lat, w.lng)));
            setCatalogData(featureCollection(data.filter((p) => !have.has(spot(p.lat, p.lng)))
                .map((p) => point([p.lng, p.lat], { id: p.id, guid: p.guid, title: p.title || '(unnamed portal)', image: p.image || '' }))));
        } catch (e) { /* transient — leave the last set shown */ }
    }

    function scheduleCatalog() { clearTimeout(catalogTimer); catalogTimer = setTimeout(loadCatalog, 300); }

    function onCatalogClick(e) {
        const f = e.features?.[0];
        if (!f || !portalsOn.value) return;
        hideTitle(ctx.map); // the add popup takes over from the hover name
        const p = f.properties;
        if (skipConfirm.value) { ctx.emit('add-catalog', { id: +p.id }); return; } // one-tap add (user opted out of the prompt)
        openAddPopup(f.geometry.coordinates.slice(), p);
    }

    function openAddPopup(coords, p) {
        catalogPopup?.remove();
        const wrap = document.createElement('div');
        wrap.className = 'catalog-add';

        if (p.image) { // catalog photo (Niantic) — only harvested portals have one
            const img = document.createElement('img');
            img.className = 'catalog-add-img';
            img.src = p.image; img.alt = ''; img.loading = 'lazy';
            wrap.appendChild(img);
        }
        const title = document.createElement('div');
        title.className = 'catalog-add-title';
        title.textContent = p.title; // textContent, never innerHTML — a catalog title is user-supplied
        wrap.appendChild(title);

        const skip = document.createElement('label');
        skip.className = 'catalog-add-skip';
        const cb = document.createElement('input'); cb.type = 'checkbox';
        const cbTxt = document.createElement('span'); cbTxt.textContent = "Don't ask again";
        skip.append(cb, cbTxt);

        const add = document.createElement('button');
        add.type = 'button'; add.className = 'catalog-add-btn'; add.textContent = 'Add to plan';
        add.addEventListener('click', () => {
            if (cb.checked) skipConfirm.value = true; // future taps add immediately
            ctx.emit('add-catalog', { id: +p.id });
            catalogPopup?.remove();
        });
        wrap.appendChild(add);

        if (isOwner.value) { // jump to this portal on the (owner-only) catalog page
            const open = document.createElement('button');
            open.type = 'button'; open.className = 'catalog-add-link'; open.textContent = 'Open in catalog';
            open.addEventListener('click', () => { catalogPopup?.remove(); router.visit('/catalog?q=' + encodeURIComponent(p.title || '') + '&focus=' + (+p.id)); });
            wrap.appendChild(open);
        }
        wrap.appendChild(skip);

        catalogPopup = new maplibregl.Popup({ closeButton: true, closeOnClick: true, offset: 12, maxWidth: '240px' }).setLngLat(coords).setDOMContent(wrap).addTo(ctx.map);
    }

    function togglePortals() { portalsOn.value = !portalsOn.value; }

    function dispose() { clearTimeout(catalogTimer); catalogPopup?.remove(); }

    return { portalsOn, catalogHint, loadCatalog, scheduleCatalog, togglePortals, dispose };
}
