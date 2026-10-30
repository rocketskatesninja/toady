<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\MasterPortal;
use App\Models\Op;
use App\Support\FieldPlanImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OpPlanController extends Controller
{
    use AuthorizesOpAccess;

    /**
     * Import an IITC field plan (Draw Tools or Bookmarks export) → waypoints + link directives + key needs.
     * Each drawn portal becomes a waypoint (catalog-matched for its name + intel); each drawn edge becomes
     * a `link` directive at the origin, and bumps the destination's keys_needed (one key per inbound link).
     */
    public function import(Request $request, Op $op): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $this->requirePlanning($op);

        $request->validate([
            'plan' => ['required', 'string', 'max:1000000'],
            'portals_only' => ['sometimes', 'boolean'],
        ]);
        $raw = $request->input('plan');
        $portalsOnly = $request->boolean('portals_only'); // Waypoints widget: add the portals only, skip links/keys
        $json = json_decode($raw, true);
        if (! is_array($json)) {
            return back()->withErrors(['plan' => 'That isn’t valid JSON — paste an IITC Draw Tools or Bookmarks export.']);
        }

        $parsed = FieldPlanImporter::parse($json);
        if (! count($parsed['portals'])) {
            return back()->withErrors(['plan' => 'No portals found in that export.']);
        }

        $seq = (int) $op->waypoints()->max('seq');
        $wpId = [];
        // portals already in the op (and ones added earlier in this import), to skip duplicates by location
        $seen = $op->waypoints()->whereNotNull('lat')->get(['id', 'lat', 'lng'])
            ->map(fn ($w) => ['id' => $w->id, 'lat' => (float) $w->lat, 'lng' => (float) $w->lng])->all();
        $added = 0;
        foreach ($parsed['portals'] as $i => $p) {
            $portal = MasterPortal::locate(($p['guid'] ?? null) ?: null, (float) $p['lat'], (float) $p['lng']);
            $lat = (float) ($portal->lat ?? $p['lat']);
            $lng = (float) ($portal->lng ?? $p['lng']);

            // a portal at (essentially) the same spot is a duplicate — reuse it so links still connect, don't re-add
            $dupe = collect($seen)->first(fn ($s) => abs($s['lat'] - $lat) < 1e-5 && abs($s['lng'] - $lng) < 1e-5);
            if ($dupe) {
                $wpId[$i] = $dupe['id'];
                continue;
            }

            $wp = $op->waypoints()->create($portal
                ? ['seq' => ++$seq, 'role' => 'waypoint'] + $portal->toWaypoint()
                : ['seq' => ++$seq, 'role' => 'waypoint', 'title' => $p['label'] ?: null, 'lat' => $p['lat'], 'lng' => $p['lng']]);
            $wpId[$i] = $wp->id;
            $seen[] = ['id' => $wp->id, 'lat' => $lat, 'lng' => $lng];
            $added++;
        }

        $skipped = count($parsed['portals']) - $added;
        $dupeNote = $skipped ? " ({$skipped} duplicate".($skipped === 1 ? '' : 's').' skipped)' : '';

        if ($portalsOnly) {
            return back()->with('success', $added.' portals added.'.$dupeNote);
        }

        // one link directive per drawn edge (the target shows as the directive's 2nd field), and one key per inbound link
        $op->appendSteps(array_map(fn ($l) => ['action' => 'link', 'op_waypoint_id' => $wpId[$l[0]], 'links' => [$wpId[$l[1]]]], $parsed['links']));
        $need = array_count_values(array_map(fn ($l) => $wpId[$l[1]], $parsed['links']));
        foreach ($need as $wid => $n) {
            $op->waypoints()->where('id', $wid)->update(['keys_needed' => $n]);
        }

        return back()->with('success', $added.' portals · '.count($parsed['links']).' links imported.'.$dupeNote);
    }
}
