<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\MasterPortal;
use App\Models\Op;
use App\Models\OpWaypoint;
use App\Models\User;
use App\Support\CatalogContributor;
use App\Support\TravelTools;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OpWaypointController extends Controller
{
    use AuthorizesOpAccess;

    /** Add a location — a catalog portal (snapshot intel), raw lat/lng (map-drop/link), or a GENERIC named location with no coords yet. */
    public function store(Request $request, Op $op): RedirectResponse
    {
        $this->requireEditable($op, $request->user());

        $data = $request->validate([
            'portal_id' => ['nullable', 'integer'],
            'guid' => ['nullable', 'string', 'max:64'], // portal id captured from a scanner Share link
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'title' => ['nullable', 'string', 'max:160'],
            'role' => ['nullable', Rule::in(OpWaypoint::ROLES)],
        ]);

        $hasCoords = isset($data['lat'], $data['lng']);
        // the picked catalog portal, else the cataloged portal at the dropped coordinates
        $catalog = (! empty($data['portal_id']) ? MasterPortal::visible()->find($data['portal_id']) : null)
            ?? ($hasCoords ? MasterPortal::nearestTo((float) $data['lat'], (float) $data['lng'])->first() : null);

        $attrs = match (true) {
            $catalog !== null => ['guid' => $catalog->guid] + $catalog->toWaypoint(),
            // no cataloged portal here — a blank name (e.g. the map-drop prompt left empty) gets a best-effort one
            // from the nearest OSM feature (portals are usually mapped POIs) instead of saving ""
            $hasCoords => [
                'guid' => $data['guid'] ?? null, 'lat' => $data['lat'], 'lng' => $data['lng'],
                'title' => trim((string) ($data['title'] ?? '')) !== '' ? $data['title'] : TravelTools::nameFor((float) $data['lat'], (float) $data['lng']),
            ],
            ! empty($data['title']) => ['title' => $data['title'], 'lat' => null, 'lng' => null], // a generic, unplaced location
            default => throw ValidationException::withMessages(['title' => 'Give the location a portal, coordinates, or a name.']),
        };

        // a new location goes to the TOP of the list (push the rest down); spine is the common fan-field portal —
        // operatives re-tag anchors/targets as needed
        $op->waypoints()->increment('seq');
        $waypoint = $op->waypoints()->create(['seq' => 1, 'role' => $data['role'] ?? 'spine'] + $attrs);
        $this->contributeName($waypoint, $data['title'] ?? null, $request->user());

        return back();
    }

    /** Edit a location, or attach a portal/coords to a generic one (pass portal_id to snapshot a catalog portal). */
    public function update(Request $request, Op $op, OpWaypoint $waypoint): RedirectResponse
    {
        $this->requireEditable($op, $request->user());

        $data = $request->validate([
            'role' => ['sometimes', Rule::in(OpWaypoint::ROLES)],
            'portal_id' => ['sometimes', 'integer'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
        ] + OpWaypoint::intelRules(partial: true));

        if (! empty($data['portal_id']) && ($p = MasterPortal::visible()->find($data['portal_id']))) {
            $waypoint->update($p->toWaypoint() + ['role' => $data['role'] ?? $waypoint->role, 'guid' => $p->guid]);
        } else {
            unset($data['portal_id']);
            $waypoint->update($data);
            $this->contributeName($waypoint, $data['title'] ?? null, $request->user());
        }

        return back();
    }

    /**
     * An operator who typed a name for a PLACED portal crowd-sources it to the shared catalog, and we link
     * the waypoint to the resolved catalog entry so its trust badge shows. No-op for coordinate-less
     * waypoints or names we derived ourselves (a blank input that fell back to an OSM lookup) — only a
     * human-typed name is a vote. CatalogContributor also re-checks eligibility, so this stays best-effort.
     */
    private function contributeName(OpWaypoint $waypoint, ?string $typedTitle, User $user): void
    {
        if (trim((string) $typedTitle) === '' || $waypoint->lat === null) {
            return;
        }
        $portal = CatalogContributor::contribute($user, [
            'title' => $typedTitle,
            'lat' => (float) $waypoint->lat,
            'lng' => (float) $waypoint->lng,
            'guid' => $waypoint->guid,
        ]);
        if ($portal && ! $waypoint->guid) {
            $waypoint->update(['guid' => $portal->guid]);
        }
    }

    /**
     * Operative sets op-local portal intel (gate pin, parking, hours, access, hazards) — a per-op
     * overlay on the master catalog, visible only to op participants and purged with the op. Editable
     * during planning only; once the op is active the intel is read-only (like the rest of the plan).
     */
    public function intel(Request $request, Op $op, OpWaypoint $waypoint): RedirectResponse
    {
        $this->requireEditable($op, $request->user());

        $waypoint->update($request->validate(OpWaypoint::intelRules(partial: true)));

        return back();
    }

    /** Dispute a shared-catalog name as wrong. Any eligible op member may flag; enough flags hide the name. */
    public function flag(Request $request, Op $op, OpWaypoint $waypoint): RedirectResponse
    {
        $user = $request->user();
        $this->requireLive($op, $user);
        $this->requireVisibleWaypoint($op, $user, $waypoint->id);
        // say what actually happened — the button used to report "Flagged" even when nothing was recorded
        if (! $user->canContributeCatalog()) {
            return back()->with('error', 'Your account can’t flag catalog names right now.');
        }
        $portal = MasterPortal::locate($waypoint->guid, $waypoint->lat !== null ? (float) $waypoint->lat : null, $waypoint->lng !== null ? (float) $waypoint->lng : null);
        if (! $portal) {
            return back()->with('error', "“{$waypoint->title}” isn’t in the shared catalog, so there’s no catalog name to flag.");
        }
        if (! $portal->flags()->firstOrCreate(['user_id' => $user->id])->wasRecentlyCreated) {
            return back()->with('success', "You’ve already flagged “{$portal->title}” — an owner will review it.");
        }
        $wasHidden = $portal->status === MasterPortal::HIDDEN;
        $portal->recomputeFlagStatus();
        if (! $wasHidden && $portal->status === MasterPortal::HIDDEN) {
            return back()->with('success', "Flagged — “{$portal->title}” is now hidden from automatic naming until an owner reviews it.");
        }

        return back()->with('success', 'Flagged — an owner will review the name.');
    }

    public function reorder(Request $request, Op $op): RedirectResponse
    {
        $this->requireEditable($op, $request->user());
        $data = $request->validate(['order' => ['array', 'max:500'], 'order.*' => ['integer']]);
        foreach ($data['order'] ?? [] as $i => $id) {
            $op->waypoints()->where('id', $id)->update(['seq' => $i + 1]);
        }

        return back();
    }

    public function destroy(Request $request, Op $op, OpWaypoint $waypoint): RedirectResponse
    {
        $this->requireEditable($op, $request->user());
        $waypoint->delete(); // its directives and key holdings cascade

        return back();
    }

    /** Delete every portal in the op — the Plan panel's "delete all · portals". */
    public function clearAll(Request $request, Op $op): RedirectResponse
    {
        $this->requireEditable($op, $request->user());
        $n = $op->waypoints()->count();
        $op->waypoints()->delete(); // directives and key holdings cascade-delete with the waypoints

        return back()->with('success', $n ? 'Cleared '.$n.' '.Str::plural('portal', $n).' and their directives.' : 'No portals to clear.');
    }
}
