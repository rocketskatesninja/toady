# Changelog

Notable, user-facing changes to toady, newest first. Grouped by the day the work landed on `master`.

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
