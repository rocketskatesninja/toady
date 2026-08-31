<script setup>
import DocLayout from '@/Layouts/DocLayout.vue';
import { WIDGET_ICONS } from '@/icons';

// ───────────────────────────────────────────────────────────────────────────
// The guide is data-driven — edit these arrays to change it:
//   steps  — the numbered "run an op" walkthrough, start to finish
//   panels — the reference at the bottom; each entry's icon comes from WIDGET_ICONS by `key`
// Strings are HTML (rendered with v-html); they're hardcoded here, never user input.
// ───────────────────────────────────────────────────────────────────────────

const steps = [
    'Create the op from your dashboard (<span class="text-accent">+ new op</span>), then open <span class="text-ink">Edit op</span> to set its description, goals, and type. Everything else happens in the <span class="text-ink">Plan</span> panel. While the op\'s in <span class="text-ink">planning</span>, load your portals — <span class="text-accent">import an IITC plan</span> to drop the whole field in at once, or add them one by one by name, Intel/scanner link, or from your catalog. The guided setup then walks you through it: tag your <span class="text-ink">anchors</span> (two for a double-anchor fan, or one for a single-anchor fan) and hit <span class="text-accent">Auto</span> — it promotes the rest to spines and lays down every <span class="text-accent">link directive</span>, a per-location <span class="text-accent">farm-keys</span> directive, and each key target in throw order (the <span class="text-ink">links / keys / both</span> option picks what it generates). Building something other than a fan? Set the key counts and directives by hand.',
    'Scout every location in the <span class="text-ink">Plan</span> panel: confirm the <span class="text-ink">keys needed</span> for each, and lay in the field intel your agents will want on the ground — <span class="text-accent">gate PIN</span>, parking, hours, access notes, hazards — each with one-tap navigate + Intel links. Now\'s also the time to bring people in: share the op\'s <span class="text-accent">invite link</span> (or let agents <span class="text-accent">scan its QR</span> to join), or add agents by callsign.',
    'Fine-tune the plan in the <span class="text-ink">Plan</span> panel — assign specific agents to specific actions at each portal (hack, deploy, capture, mods, viri, farm keys…). The <span class="text-ink">Roster</span> then shows each agent\'s <span class="text-accent">required loadout</span> — exact resonators by level, mods, viri, and keys-per-portal. Leave any team-wide instructions in the <span class="text-ink">Notes</span> panel\'s op notes so the whole team sees them.',
    'When everyone\'s set, flip the op to <span class="text-accent">active</span> — every agent gets a "go" and the plan locks against mid-op edits. Run it from the <span class="text-ink">Map</span>: watch agents move live and see objectives <span class="text-ink">scratched off</span> as they\'re completed. Coordinate in <span class="text-ink">Comms</span> (<span class="font-mono text-accent">@callsign</span> to ping), and keep the <span class="text-ink">Advisories</span> panel up — it flags whatever still needs attention and names the exact portal.',
    'Follow it to the finish in the <span class="text-ink">Progress</span> panel — directives done, links thrown, fields made, keys farmed. Open the <span class="text-accent">after-action report</span> any time from there (not just at 100%) and <span class="text-accent">download it</span> — <span class="font-mono">.txt</span> to paste into comms or <span class="font-mono">.json</span> for your records — before you close the op, since closing purges everything. Share it on social media, and <span class="text-ink">send us your photos and stories</span> to be featured on the <a href="/showcase" class="text-accent hover:underline">showcase</a>.',
];

const panels = [
    { key: 'map', name: 'Map', b: 'The live battlefield: every agent\'s position, your planned links, weather/radar, and traffic. Portals are coloured by status — <span class="text-ink">gray</span> untouched, <span class="text-ink">yellow</span> in progress, your faction\'s colour once all directives are done, and <span class="text-rose-400">red</span> while a portal is short on keys. Each agent\'s beacon shows in the <span class="text-ink">colour you assign them</span> in the Roster, and from there you can draw any agent\'s <span class="text-ink">predicted route</span> in that colour — with its <span class="text-accent">walking distance and on-foot ETA</span>, and an <span class="text-accent">optimize walk</span> toggle that reorders the farm/capture stops for the shortest route (link throws stay in fielding order). Tap a portal to highlight it in the lists (and vice-versa); the crosshair toggles auto-zoom-on-select, and the padlock freezes the view in place. While planning, flip <span class="text-accent">portals</span> on to overlay every cataloged portal in view — hover a dot for its name, tap it to add it to the plan. On a phone, the <span class="text-ink">compass</span> button rotates the map to point the way you\'re facing (tap again for north-up) — handy when you\'re walking the field.' },
    { key: 'plan', name: 'Plan', b: 'The heart of the op — every location in one list, each card holding its <span class="text-ink">keys</span>, <span class="text-ink">ground intel</span>, and <span class="text-ink">directives</span> together. While the op\'s in <span class="text-ink">planning</span>, operators get the full build surface: add / import / remove portals, set each one\'s <span class="text-ink">role</span>, and build the whole fan from your anchors with <span class="text-accent">Auto</span> (the <span class="text-ink">links / keys / both</span> option picks whether it lays the link directives, a per-location farm-keys directive, or both) — plus <span class="text-accent">assign the whole fan</span> to one agent, drop one directive onto <span class="text-ink">every portal at once</span> with <span class="text-accent">To all</span> (pick the action, its link target / mod / key count, and the agent), save and re-apply <span class="text-accent">directive templates</span>, and <span class="text-accent">delete all</span> — every portal, or just their actions — in one tap. Each card shows its <span class="text-ink">key count</span> (need vs held vs short), who\'s holding what, the <span class="text-ink">farm effort</span> to close the gap, and one-tap <span class="text-accent">navigate</span> / <span class="text-accent">Intel</span> links; portals still short on keys turn <span class="text-rose-400">red</span> here and on the map. <span class="text-ink">Sort</span> the list by <span class="text-ink">planned order, name, or distance</span> (nearest first, from your live location) at any time — it changes only the view, never the plan. Once the op goes <span class="text-accent">active</span> it becomes a clean checklist — agents check off their own assigned directives and report the keys they\'re holding.' },
    { key: 'roster', name: 'Roster', b: 'Everyone in the op. Tap an agent to reach them via the contact info they chose to share (phone, Telegram, email) and to see their <span class="text-ink">required loadout</span> — the exact resonators (broken out by level), mods, viri, and keys-per-portal their directives call for, with each key need flagged <span class="text-ink">held</span> or <span class="text-rose-400">short</span>. Operators can give each agent a <span class="text-ink">colour</span> (it tints their map beacon, route line, and avatar ring), draw that agent\'s <span class="text-ink">predicted route</span> on the map, and promote/demote or remove/ban agents from here.' },
    { key: 'dms', name: 'Comms', b: 'Op-wide chat plus 1:1 DMs. Type <span class="font-mono text-accent">@callsign</span> to ping someone directly. Operators can type <span class="font-mono text-accent">@all</span> or <span class="font-mono text-accent">@op</span> to broadcast to the whole op at once — everyone gets a notification.' },
    { key: 'progress', name: 'Progress', b: 'The mission at a glance — directives done, links thrown, fields made, and keys farmed, broken out so you can see exactly what\'s left.' },
    { key: 'advisories', name: 'Advisories', b: 'Live readiness warnings and field tips. It <span class="text-ink">names the exact portal</span> with a gap — no directives, no portal pinned, a link missing its target, or a <span class="text-ink">link too long to throw</span> (past solo or even L8 range) — plus keys still to farm, agents not sharing location, a stalled op, weather/traffic nudges, and <span class="text-ink">cycle reminders</span> — a checkpoint or the cycle-end approaching.' },
    { key: 'activity', name: 'Activity log', b: '<span class="text-ink">Operators only.</span> A running feed of agents joining, directives completed (who, what, when), and key reports — so you can see what\'s happening across the whole op at a glance.' },
    { key: 'notes', name: 'Notes', b: 'Two tabs: <span class="text-ink">My notes</span> — a private scratchpad only you see (gate codes, a personal checklist); and <span class="text-ink">Op notes</span> — operator-written notes the whole team reads. Both autosave, survive a logout, and clear when the op closes.' },
    { key: 'weather', name: 'Weather', b: 'The forecast for the op area — hourly conditions plus sunset and golden-hour, so you can time the field.' },
    { key: 'cycle', name: 'Cycle', b: 'The Ingress scoring clock — a live countdown to the <span class="text-ink">next checkpoint</span> (every 5 hours) and to the end of the current <span class="text-ink">cycle</span>, with where you stand in it (checkpoint N of M) and the real cycle designation (e.g. <span class="text-accent">2026.26</span>). No scanner needed — an admin sets one checkpoint anchor and toady extrapolates the whole schedule, in your own local time. Throw your fields just before a checkpoint so the Mind Units bank for that cycle.' },
    { key: 'notifications', name: 'Notifications', b: 'Your personal feed — assignments, mentions, DMs, key milestones, "go" alerts. The gear chooses exactly which alerts you get and whether they vibrate your phone.' },
    { key: 'ai', name: 'AI Concierge', b: 'Your own AI helper — bring an <span class="text-ink">OpenAI or Anthropic API key</span> (browser-only by default; encrypted server-side only if you opt into cross-device sync) and ask it anything. It knows toady inside out <span class="text-ink">and</span> your live op, and doubles as a travel concierge: real parking, paths, tides, elevation, and live web search to work out how to actually reach a portal.' },
];
</script>

<template>
    <DocLayout title="Run an op" tag="From zero to a live, coordinated field op.">
        <p>
            toady has two roles. The <span class="text-accent">Operator</span> builds and runs the op; an
            <span class="text-accent">Agent</span> joins via the Operator's link and executes. If you created the op,
            you're the Operator — here's the whole loop, start to finish.
        </p>

        <ol class="space-y-4 list-none">
            <li v-for="(s, i) in steps" :key="i" class="flex gap-3">
                <div class="shrink-0 mt-0.5 w-6 h-6 rounded border border-emerald-500/30 bg-surface font-mono text-accent text-xs flex items-center justify-center">{{ i + 1 }}</div>
                <p class="min-w-0" v-html="s"></p>
            </li>
        </ol>

        <div class="border border-emerald-500/20 rounded-lg bg-surface px-3 py-3">
            <p class="hud-label font-mono text-[11px] uppercase tracking-wider text-ink-dim mb-1.5">Field tip</p>
            <p class="text-sm">
                Plan and key everything while in <span class="text-ink">planning</span>, then go <span class="text-accent">active</span>
                only when the team's rolling — that keeps agents focused on the "go" and your plan safe from accidental edits.
                Assigning a directive just earmarks it; the agent acts once the op's live (and, in an ordered op, once it's their turn).
            </p>
        </div>

        <section>
            <h2 class="font-mono text-ink text-base mb-1 pb-1.5 border-b border-line">Loading portals</h2>
            <p class="text-sm mb-3">
                While the op's in <span class="text-ink">planning</span>, the <span class="text-ink">Plan</span> panel loads portals a few ways — and
                <span class="text-ink">which one you use decides whether they arrive already named</span>. A portal's name lives on Niantic's servers,
                not in a link, and toady never scrapes the Intel API — so the name can only come from a source that already carries it.
            </p>
            <ul class="space-y-2 text-sm list-none">
                <li><span class="font-mono text-accent">✓ names — IITC Bookmarks.</span> The reliable way to bulk-load <span class="text-ink">named</span> portals, anywhere you play.
                    Bookmark the portals for your op in IITC, <span class="text-ink">export bookmarks</span>, and import that JSON — the real names ride along in the file. No catalog needed.</li>
                <li><span class="font-mono text-accent">✓ names + links — IITC planner export.</span> A draw-tools export that also carries portal titles brings your
                    <span class="text-ink">links and fields</span> as geometry <span class="text-ink">and</span> names every portal.</li>
                <li><span class="font-mono text-amber-300">✗ no names — IITC Draw Tools.</span> Plain geometry: you get the links and the dots, but each portal lands <span class="text-rose-400">Untitled</span>.</li>
                <li><span class="font-mono text-amber-300">✗ no names — Intel / scanner links.</span> A <span class="font-mono">pll</span> link or an in-app <span class="text-ink">Share</span> link is only coordinates
                    (plus an opaque ID) — the name isn't in it, so the portal drops at the right spot but <span class="text-rose-400">Untitled</span>.</li>
                <li><span class="font-mono text-accent">✓ by name.</span> Add a single portal by typing its name outright.</li>
                <li><span class="font-mono text-accent">✓ names — off the map.</span> On the <span class="text-ink">Map</span>, toggle <span class="text-accent">portals</span> on to see every <span class="text-ink">cataloged</span> portal in view, then tap one to drop it into the plan <span class="text-ink">already named</span> — with its photo and any saved intel. Those names come from the shared catalog agents build as they name their own portals.</li>
            </ul>
            <p class="text-sm mt-3">
                <span class="text-ink">Untitled is a quick fix:</span> expand the portal's card while the op's in planning and <span class="text-accent">click its name</span> to edit it.
                But the shortcut past all the renaming is simple — <span class="text-ink">export Bookmarks from IITC</span>. It's the one method that carries real portal
                names for anywhere you play, no catalog required.
            </p>
        </section>

        <section>
            <h2 class="font-mono text-ink text-base mb-1 pb-1.5 border-b border-line">The AI Concierge</h2>
            <p class="text-sm mb-3">
                The concierge is your own assistant, built into the op. Bring an <span class="text-ink">OpenAI or Anthropic API key</span> and ask it
                anything. By default the key stays <span class="text-ink">only in your browser</span> and goes straight to the provider — never stored on
                our servers; opt into <span class="text-ink">cross-device sync</span> and it's kept <span class="text-ink">encrypted</span> on the server
                instead, tied to your account and wiped the moment you turn sync off. It reads your
                <span class="text-ink">live op</span> — the roster, every portal, keys held vs. needed, the directives, and the status — and knows
                toady inside out, so its answers are about <span class="text-ink">your</span> op, not generic advice. It's opt-in: skip the key and
                everything else works exactly the same.
            </p>
            <p class="text-sm mb-2">To help plan an op, it can pull from:</p>
            <ul class="space-y-2 text-sm list-none">
                <li><span class="font-mono text-accent">Your op + the toady manual.</span> The plan, portals, key counts, roster, directives, and status — plus how every panel and feature works.</li>
                <li><span class="font-mono text-accent">Live web search.</span> Current info off the web: business hours, closures, public transit, <span class="text-ink">ferry schedules</span>, event news, directions.</li>
                <li><span class="font-mono text-accent">Maps &amp; OpenStreetMap.</span> Real ground detail around a portal — <span class="text-ink">parking</span>, footpaths, gates, and boat ramps / slipways for water access.</li>
                <li><span class="font-mono text-accent">Place lookup.</span> Turns a place name into coordinates (and back), so you can ask about a spot by name.</li>
                <li><span class="font-mono text-accent">Tides.</span> NOAA high/low tide predictions for coastal and marsh portals, so you time a shoreline approach right.</li>
                <li><span class="font-mono text-accent">Elevation.</span> The terrain height at a coordinate — handy for hills and how hard a portal is to reach.</li>
                <li><span class="font-mono text-accent">Travel guides.</span> Wikivoyage "get in / get around" notes when you're planning a trip op somewhere new.</li>
            </ul>
            <p class="text-sm mt-3">
                So you can ask things like <span class="text-ink">"what's the closest parking to the west anchor, and how far is the walk?"</span>,
                <span class="text-ink">"is there a boat ramp near these portals, and what's the tide doing tonight?"</span>, or
                <span class="text-ink">"which of my agents is short on keys, and for which portals?"</span> — and it answers from your actual plan.
            </p>
            <p class="text-sm mt-3">
                <span class="text-ink">Stuck on an Untitled portal?</span> Ask what's at its coordinates — <span class="text-ink">"what landmarks or
                public art are around 30.35, -81.43?"</span> — and it'll surface nearby memorials, murals, and features from maps and the web to jog
                your memory. Treat it as a <span class="text-ink">hint to verify</span>, not gospel: the real portal name lives only in Niantic's data
                (an IITC <span class="text-accent">Bookmarks</span> export is what carries it), so confirm before you type it in.
            </p>
        </section>

        <section>
            <h2 class="font-mono text-ink text-base mb-1 pb-1.5 border-b border-line">Panel reference</h2>
            <p class="text-sm mb-3">
                Every panel can be dragged, resized, collapsed, or added/removed from your dashboard (the ▲ menu also opens
                each as a full page). Here's what each one does:
            </p>
            <div class="space-y-2">
                <div v-for="p in panels" :key="p.key" class="border border-line rounded-lg bg-surface px-3 py-2.5">
                    <h3 class="flex items-center gap-2 font-mono text-ink text-sm mb-1">
                        <component :is="WIDGET_ICONS[p.key]" :size="15" class="text-accent shrink-0" /> <span>{{ p.name }}</span>
                    </h3>
                    <p class="text-sm" v-html="p.b"></p>
                </div>
            </div>
            <p class="text-sm mt-3">
                <span class="text-ink">Dashboard tip:</span> on <span class="text-accent">desktop</span>, spread out — run the full set of panels so you can see everything at once. On <span class="text-accent">mobile</span>, keep it lean: stick to the <span class="text-ink">Map</span> and <span class="text-ink">Plan</span> so it stays fast and readable in the field.
            </p>
        </section>

        <div class="border border-emerald-500/20 rounded-lg bg-surface px-3 py-3">
            <p class="hud-label font-mono text-[11px] uppercase tracking-wider text-ink-dim mb-1.5">Getting help</p>
            <p class="text-sm">
                Stuck, or hit a bug? Open the menu and tap <span class="text-accent">Report a problem</span> — describe what
                happened, attach a screenshot or two, and leave your email if you'd like a reply. It goes straight to the team.
            </p>
        </div>
    </DocLayout>
</template>
