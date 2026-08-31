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
            $portal = ! empty($p['guid']) ? MasterPortal::where('guid', $p['guid'])->first() : null;
            $portal ??= MasterPortal::nearestTo((float) $p['lat'], (float) $p['lng'])->first();
            $lat = (float) ($portal->lat ?? $p['lat']);
            $lng = (float) ($portal->lng ?? $p['lng']);

            // a portal at (essentially) the same spot is a duplicate — reuse it so links still connect, don't re-add
            $dupe = null;
            foreach ($seen as $s) {
                if (abs($s['lat'] - $lat) < 1e-5 && abs($s['lng'] - $lng) < 1e-5) {
                    $dupe = $s['id'];
                    break;
                }
            }
            if ($dupe !== null) {
                $wpId[$i] = $dupe;
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

        $stepSeq = (int) $op->steps()->max('seq');
        $need = [];
        foreach ($parsed['links'] as [$a, $b]) {
            $from = $wpId[$a];
            $to = $wpId[$b];
            $op->steps()->create([
                'phase' => 'run', 'seq' => ++$stepSeq, 'action' => 'link',
                'op_waypoint_id' => $from, 'links' => [$to], // the target shows as the directive's 2nd field (from links)
            ]);
            $need[$to] = ($need[$to] ?? 0) + 1;
        }
        foreach ($need as $wid => $n) {
            $op->waypoints()->where('id', $wid)->update(['keys_needed' => $n]);
        }

        return back()->with('success', $added.' portals · '.count($parsed['links']).' links imported.'.$dupeNote);
    }
}
