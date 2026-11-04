#!/usr/bin/env node
// Re-shoot the README screenshots (docs/screenshots/*.png) from a running toady — staging, normally.
//
//   TOADY_EMAIL=… TOADY_PASSWORD=… TOADY_OP=<op public id> node bin/screenshots.mjs
//
// Needs Chrome/Chromium and puppeteer-core, which isn't a project dependency — install it out of tree:
//   npm i --no-save puppeteer-core        (or point PUPPETEER_CORE at an existing install)
// Chat emoji only draw if the machine has a colour-emoji font (fontconfig; FONTCONFIG_FILE can add one).
//
// Options (env):
//   TOADY_URL       the instance to shoot                          default https://toady.local
//   TOADY_OP        the op to feature (its public id)              required for shots 02–04
//   FACTION         ENL | RES — shoot in that faction's colours, then switch the account back
//   REDACT_AS       what the account's callsign reads as           default "Operator Alpha" ('' = don't redact)
//   ONLY            comma list of shots to (re)take, e.g. 02,04    default all
//   CHROME          browser binary                                 default /usr/bin/google-chrome
//
// The featured op is shown with the default dashboard (read from app/Dashboard/OpWidgets.php; just its map + plan
// for the menu shot), night theme, links + fields on; the account's own saved layout and faction are put back afterwards, even if a shot fails.
import { createRequire } from 'node:module';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const OUT = path.join(ROOT, 'docs/screenshots');
const BASE = (process.env.TOADY_URL || 'https://toady.local').replace(/\/$/, '');
const { TOADY_EMAIL: EMAIL, TOADY_PASSWORD: PASSWORD, TOADY_OP: OP, FACTION } = process.env;
const REDACT_AS = process.env.REDACT_AS ?? 'Operator Alpha';
const ONLY = (process.env.ONLY || '').split(',').map((s) => s.trim()).filter(Boolean);
const want = (id) => !ONLY.length || ONLY.some((o) => id.startsWith(o));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

if (!EMAIL || !PASSWORD) { console.error('Set TOADY_EMAIL and TOADY_PASSWORD (an email/password account on the instance).'); process.exit(1); }
const puppeteer = require(process.env.PUPPETEER_CORE || 'puppeteer-core');

// the default desktop dashboard, straight from the PHP (one source of truth)
function defaultDesktopLayout() {
    const php = readFileSync(path.join(ROOT, 'app/Dashboard/OpWidgets.php'), 'utf8');
    const block = php.match(/const DEFAULTS = \[([\s\S]*?)\n\s*\];/)[1];
    return [...block.matchAll(/\['i' => '(\w+)', 'x' => (\d+), 'y' => (\d+), 'w' => (\d+), 'h' => (\d+)\]/g)]
        .map(([, i, x, y, w, h]) => ({ i, x: +x, y: +y, w: +w, h: +h }));
}

// In the page: the op page's widget context (props + actions), found through Vue's component tree.
const CTX = `(() => {
    const seen = new Set();
    const walk = (v) => {
        if (!v || seen.has(v)) return null; seen.add(v);
        const i = v.component;
        if (i) { if (i.provides && i.provides.opctx) return i.provides.opctx; const r = walk(i.subTree); if (r) return r; }
        for (const ch of [...(Array.isArray(v.children) ? v.children : []), ...(v.dynamicChildren || [])]) { const r = walk(ch); if (r) return r; }
        return null;
    };
    return walk(document.querySelector('#app')._vnode);
})()`;

const browser = await puppeteer.launch({
    executablePath: process.env.CHROME || '/usr/bin/google-chrome', headless: 'new', acceptInsecureCerts: true,
    args: ['--ignore-certificate-errors', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--hide-scrollbars'],
});
const page = await browser.newPage();
page.on('pageerror', (e) => console.warn('page error:', e.message));
const restore = [];
let callsign = '';

// Before a shot: the account's callsign reads as REDACT_AS, and a staging TEST badge reads as production's BETA.
async function tidy() {
    await page.evaluate((callsign, as) => {
        if (callsign && as) {
            const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
            for (let n; (n = w.nextNode());) if (n.nodeValue.includes(callsign)) n.nodeValue = n.nodeValue.split(callsign).join(as);
            document.querySelectorAll('[title]').forEach((el) => el.setAttribute('title', el.getAttribute('title').split(callsign).join(as)));
            // photo-less avatars show the callsign's first letter (Avatar.vue)
            document.querySelectorAll('span.rounded-full.uppercase').forEach((el) => { if (el.textContent.trim().toUpperCase() === callsign[0].toUpperCase()) el.textContent = as[0]; });
        }
        const badge = [...document.querySelectorAll('header button')].find((b) => b.textContent.trim() === 'TEST');
        if (badge) { badge.textContent = 'BETA'; badge.className = badge.className.replace(/amber-(\d+)/g, (m, n) => `sky-${n}`); }
    }, callsign, REDACT_AS);
}
// A PUT from the page as the signed-in user. Not axios: the app answers form-style PUTs with a 302 back, which
// the browser would re-send as a PUT to the previous page (405) — the change is made either way.
const put = (url, body) => page.evaluate(async (url, body) => {
    const r = await fetch(url, {
        method: 'PUT', redirect: 'manual', credentials: 'same-origin', body: JSON.stringify(body),
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
    });
    if (r.type !== 'opaqueredirect' && !r.ok) throw new Error(`PUT ${url} → ${r.status}`);
}, url, body);
async function shoot(id, { full = false } = {}) {
    await tidy();
    mkdirSync(OUT, { recursive: true });
    await page.screenshot({ path: path.join(OUT, `${id}.png`), fullPage: full });
    console.log('shot', id);
}
async function openOp(viewport) {
    await page.setViewport(viewport);
    await page.goto(`${BASE}/ops/${OP}`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('.op-map-root canvas');
    // the map's controls fade after a few idle seconds — keep them (the filter stack) in the picture
    await page.addStyleTag({ content: '.op-map-root.map-idle .maplibregl-ctrl, .op-map-root > .top-2.left-2 { opacity: 1 !important; }' });
    await sleep(7000); // tiles, weather, the route proxy
}
// desktop shots: 1600 CSS px wide at 1.25x → 2000 px images

try {
    // sign in
    await page.setViewport({ width: 1600, height: 1000, deviceScaleFactor: 1.25 });
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle2' });
    await page.type('input[type=email]', EMAIL);
    await page.type('input[type=password]', PASSWORD);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[type=submit]')]);

    // whose callsign to redact, and the faction to put back (from Inertia's shared props)
    const me = await page.evaluate(() => JSON.parse(JSON.stringify(document.querySelector('#app').__vue_app__.config.globalProperties.$page.props.auth.user)));
    callsign = me.callsign || '';
    if (FACTION && FACTION !== me.faction) {
        restore.push(() => put('/profile', { faction: me.faction })); // queued first, so a half-made change still goes back
        await put('/profile', { faction: FACTION });
        await page.reload({ waitUntil: 'networkidle2' }); // spend its "Profile updated." flash before the first shot
        console.log(`faction ${me.faction} → ${FACTION} for the shoot`);
    }

    // a clean look for this browser: night theme, compact desktop menu, the map's plan layers on and the extras off
    await page.evaluate((op) => {
        localStorage.setItem('toady-theme', 'night');
        localStorage.setItem('toady-menu-style', JSON.stringify({ mobile: 'labeled', desktop: 'compact' }));
        for (const [k, v] of Object.entries({ links: '1', fields: '1', sat: 'off', radar: '0', traffic: '0', landmarks: '0', portals: '0', overlay: '', maplock: '0' })) localStorage.setItem(`toady-map-${k}:${op}`, v);
        localStorage.setItem(`toady-maplock:${op}`, '0');
        localStorage.removeItem(`toady-mapview:${op}`);
        localStorage.setItem(`toady-route-agent:${op}`, '');
    }, OP || '');

    if (want('01')) {
        await page.setViewport({ width: 1600, height: 1000, deviceScaleFactor: 1.25 });
        await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle2' });
        await sleep(2500); // map thumbnails
        await shoot('01-ops-home');
    }

    if (OP && (want('02') || want('03') || want('04'))) {
        // the full default dashboard for the shoot; the account's own layout goes back afterwards
        await openOp({ width: 1600, height: 1000, deviceScaleFactor: 1.25 });
        const saved = await page.evaluate(`JSON.parse(JSON.stringify(${CTX}.data.layouts.desktop))`);
        const setLayout = (layout) => put('/dashboard/layout', { op_id: OP, mode: 'desktop', layout });
        restore.push(() => setLayout(saved));
        await setLayout(defaultDesktopLayout());

        if (want('02')) {
            await openOp({ width: 1600, height: 1000, deviceScaleFactor: 1.25 });
            await shoot('02-op-dashboard', { full: true });
        }
        if (want('03')) {
            // just the map and plan on the grid (stretched to fill the frame), so the menu lists every other panel as a page
            await setLayout(defaultDesktopLayout().filter((w) => ['map', 'plan'].includes(w.i)).map((w) => ({ ...w, h: 23 })));
            await openOp({ width: 1600, height: 1240, deviceScaleFactor: 1.25 });
            await page.evaluate(() => document.querySelector('.mission-card .plan-handle')?.click()); // open the first portal card
            await sleep(900);
            await page.click('header button[title="menu"]');
            await sleep(700);
            await shoot('03-menu');
        }
        if (want('04')) {
            await openOp({ width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true });
            await shoot('04-mobile');
        }
    }
} finally {
    for (const undo of restore.reverse()) { try { await undo(); } catch (e) { console.error('restore failed:', e.message); } }
    if (restore.length) console.log('restored the account\'s faction / layout');
    await browser.close();
}
