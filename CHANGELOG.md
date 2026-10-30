# Changelog

Notable, user-facing changes to toady, newest first. Grouped by the day the work landed on `master`.

## 2026-10-30

### Added
- **Overlay another op** — operators get an **overlay** button on the op map that lays one of their other ops over this one, in a neutral dashed style, and flags everywhere the two plans get in each other's way: **link crossings** (the game won't let a link cross an existing one, so whichever op throws second fails), **shared portals**, and linked portals sitting **under the other op's fields** (they can't link out). Each one gets a coloured ring, and a chip sums them up; tap it to fly to each in turn. You can only overlay ops you're on, and only what you could see on that op's own page. The choice is remembered per op on your device.

### Changed
- **Catalog portals in your faction's colour** — the op map's **portals** overlay drew every cataloged portal in the same cyan for both sides; the dots are now Enlightened green or Resistance blue, matching the rest of your scanner theme in day and night mode.

### Fixed
- **Pale yellow text in light mode** — warnings, locked-field notes, the owner / upcoming / unverified badges, Guide tips, and a few widget highlights used a pale amber that was nearly invisible on white. They now switch to a dark amber in light mode (dark mode is unchanged).
- **Flag name tells you what happened** — it used to say "Flagged" even when nothing was recorded. Now it says when the portal isn't in the shared catalog, when you've already flagged that name, and when your flag is the one that hides a disputed name from automatic naming.
- **"Your turn" goes to the right agent** — in a sequential op, the alert picked the next agent by the directive's internal number, while the check-off order follows the portals; after you reordered portals, the wrong agent could be told it was their turn. Both now follow the portal order.
- **A refused "add location" no longer reshuffles the list** — adding with no portal, coordinates, or name was rejected but still pushed every portal down a slot.
- **Field counts match the map** — the Progress widget and the after-action report counted a portal visited twice in the plan as two portals (Thousandaire: 52 fields instead of the 49 the map shades). They now use the map's own field detector.
- **A new op's edit box stays closed on reload** — creating an op left `?new=1` in the address bar, so a reload reopened the edit box.
- **Map changes aren't missed while it loads** — a new portal, route, or range ring that arrived while map tiles were still loading (mid-pan or zoom) could be skipped until the next change.
- **The catalog overlay skips portals already in your plan** — it was meant to, but never matched them.
- **Copy buttons only say "copied" when it worked** — the invite link's copy said "copied" even when the browser blocked the clipboard.
- **AI concierge in strict private browsing** — saving your key could fail where the browser blocks storage.
- **Map hints readable in light mode** — the "Zoom in to load cataloged portals" / "Zoom in to see landmarks" badges and the "No compass data" note were pale cyan / rose on white; they now use a dark shade in light mode (dark mode is unchanged).

## 2026-10-29

### Added
- **Fields on the op map** — a new **fields** filter shades every field the plan's links close: faintly while a link is still to be thrown, stronger once all three are. The shading is scaled to how deeply the plan layers, so a single field is clearly tinted and an 18-layer stack still lets the streets show through. On by default, like links.
- **Landmarks on the op map** — a new **landmarks** filter shows restaurants, stores, churches, parking, and other places once you zoom in to neighborhood level, about 2 km across (a hint says so if you're too far out). Night mode gets them too, which it never had, and in both modes the labels are dark-on-light / light-on-dark with a halo, drawn above the field shading so they stay readable. Off by default, so day mode no longer shows them until you turn it on.

## 2026-10-24

### Changed
- **Compact menu: Profile is an icon** — it moved into the settings icon bar (night mode, Big View, menu style, alerts, report), leaving Log out as the only row beneath it.
- **Notifications left out of the menu** — the op's Notifications page is opened from the inbox icon in the top-right corner (in both menu styles), which keeps the compact menu on screen in normal view.

### Fixed
- **Compact menu scroll bars** — the menu no longer shows scroll bars. It's a little wider so a full row of six icons fits, and a menu taller than the screen (a small phone with Big View or larger text, or a phone turned sideways) still scrolls with a swipe, just without a bar.

## 2026-10-22

### Changed
- **Plan panel redesigned for phones** — every input, dropdown, and button is one height with labels on top, and each portal card is a stack of clear sections: nav / street / intel / copy buttons and the photo, **Portal** (type, keys needed, keys mine, held by), **Site intel**, **Directives**, and **Templates**. While planning, each directive is its own box — action and assignee, then its target / mod / key count, then a note — with bigger move and delete buttons; in an active op it's one line with a large checkbox. The layout follows the widget's own width, so desktop gets the same design with more columns when there's room.
- **Build tools collapse** to a one-line bar (remembered per device) and open into three small forms: Auto-fan, Add to every portal, and Reset. A brand-new plan keeps them open under the Getting started steps.
- The panel says "directive" throughout (it mixed "action" and "directive" before).
- **Choose your menu style** — a new **labeled** menu (every button has a word beside its icon, in two columns: Go to, the current op, Settings) sits alongside the original **compact** icon-bar menu. A button in the menu's settings row switches between them, remembered separately for mobile and desktop on each device (default: labeled on mobile, compact on desktop). The ops board always uses the compact menu, with the site links (Catalog, Reference, Guide, admin pages) as its list of labeled links — no op section, since you're not in an op there — and no style switch.
- **Big View per mode** — Big / Compact view is now remembered separately for mobile and desktop mode in each browser (switch it in the menu in either mode; an existing setting carries over to both). This works on every page, including the ops board.
- On phones the op's tools sit in the menu — labeled tiles (Undo, Invite, Edit op, Export, Close / Leave op) in the labeled menu, an icon row in the compact one — and its status is a **Status** dropdown instead of an icon you tapped to cycle planning → active → complete.
- **AI Concierge setup** — provider, API key, and model are stacked with labels and full-size controls, so the model list no longer gets squeezed off the row.

### Fixed
- **Stalled-op advisory** — "No directive completed in …" shows a readable duration ("~125 days") instead of raw minutes.
- **Ops board: empty ops couldn't be opened** — an op with no portals yet has no map thumbnail, so its card is stretched to match its row while only the top text was a link; clicking anywhere else on the card did nothing. The whole card opens the op now.
- **Occasional server errors (mostly the weather panel)** — rate-limit counters are kept in the SQLite database, and when bumping one collided with another write it failed instantly with "database is locked" (51 times since June). Database transactions now claim the write lock up front and wait their turn instead.

## 2026-10-09

### Fixed
- **Narrow-phone portal cards** — the Plan panel's "Keys & intel" header no longer crushes its label into a one-word-per-line column on small phones (the nav/street/intel/copy links wrap to their own line), and the Type / key counts / gate-pin / hours / parking facts stack in a single full-width column instead of two cramped ones.

## 2026-09-01

### Added
- **Mod rarity / type on directives** — a mod directive now takes a third dropdown: a rarity (Common / Rare / Very Rare) for Shield, Heat Sink, and Multi-hack, or an Attack / Defense type for the Transmuter. Each mod is added individually (the quantity ticker is gone).
- **Reset keys** — operators can wipe every agent's held-key counts in one action while keeping the key targets; like the other bulk resets, it's undoable.

### Changed
- **Plan panel rebuilt** — the controls below the portal name are now one **Build tools** block (auto-fan, the "to all" composer, and resets for portals / actions / keys), and each portal card is reorganized into a facts grid (type, key need/mine, who-holds-what) beside the photo, with on-site intel (gate pin, hours, parking) and access / hazards notes below. Dropdown option lists are uppercased.

### Removed
- **Farm-time estimates** — the Plan panel card and op header no longer show a key-farming time estimate; key tracking (targets vs. held) stays.

## 2026-07-11

### Added
- **Plan undo** — operators get a 10-deep undo for planning edits (auto-field, auto-farm, plan import, waypoint/directive edits, reorders, template apply). An Undo button in the op header (planning only) walks back the last ten changes; the available count shows in its tooltip.
- **Single-anchor fan** — auto-field now builds a fan from a single tagged anchor, not just two.
- **Status-change banner** — flipping an op's status shows the operator an inline confirmation (agents still get their "go" / "complete" push).

### Changed
- **"Waypoints" panel renamed to "Recon"** — across the dashboard, the in-app Guide, and the AI concierge.
- **Mobile op status is now a cycling button** in the kebab menu — one tap advances planning → active → complete, its icon and colour showing the current status (desktop keeps the dropdown).
- **Template chips** spell out their directive count on hover (e.g. "3 directives").
- Collapse chevrons on the Directives and Recon location cards now match the panel-header chevron.
- The op status badge on dashboard cards moved to the bottom-right corner.
- The AI concierge model picker lists only the **newest 10 models** per provider (was every model the provider returned).
- AI concierge settings put the provider toggle, API key, and model on one row.

### Fixed
- Anchor and target role badges are now legible in daylight mode (they washed out on the light surface).

## 2026-07-10

### Added
- Op dashboard cards show a static map thumbnail with portal dots.
- Drag-to-reorder op cards on the dashboard, saved per user.

### Changed
- The kebab menu closes on any outside tap or click.
- Collapsed dashboard panels no longer show a corner-resize handle.

## 2026-07-09

### Changed
- Dropped the per-waypoint directives counter from the ops UI.

## 2026-07-05

### Changed
- Sortable Waypoints list with a cleaned-up header.

## 2026-07-04

### Changed
- Split **auto-field** (Directives) from **auto-count** (Keys) into separate actions.
- Directive templates carry their link-to-anchor targets across ops as portable symbols.
