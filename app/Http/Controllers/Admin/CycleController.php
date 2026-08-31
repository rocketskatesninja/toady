<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin config for the global Ingress cycle timer. No score/game data is fetched — the anchor is
 * a single human-observed checkpoint time, and the site extrapolates the schedule from it with clock math.
 */
class CycleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Cycle', [
            'cycle' => Setting::get('cycle'),
            'mu_density' => (float) Setting::get('mu_density', 375),
        ]);
    }

    /** Tune the people/km² density used for the MU field-scoring estimate (region-dependent). */
    public function updateMu(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'density' => ['required', 'numeric', 'min:1', 'max:50000'],
        ]);
        Setting::put('mu_density', (float) $data['density']);

        return back()->with('success', 'MU estimate density updated.');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'anchor' => ['required', 'date'],
            'interval_hours' => ['required', 'numeric', 'min:0.25', 'max:168'],
            'checkpoints_per_cycle' => ['required', 'integer', 'min:1', 'max:1000'],
            'label' => ['required', 'string', 'regex:/^\d{4}\.\d{1,3}$/'], // the cycle designation at the anchor, e.g. "2026.26"
        ]);

        [$year, $number] = explode('.', $data['label']);
        Setting::put('cycle', [
            'anchor' => Carbon::parse($data['anchor'])->toIso8601String(), // stored as an absolute instant
            'interval_hours' => (float) $data['interval_hours'],
            'checkpoints_per_cycle' => (int) $data['checkpoints_per_cycle'],
            'year' => (int) $year,       // the designation of the cycle that STARTS at the anchor;
            'number' => (int) $number,   // the widget increments the number each elapsed cycle
        ]);

        return back()->with('success', 'Cycle timing updated.');
    }
}
