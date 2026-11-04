<?php

namespace App\Http\Middleware;

use App\Models\Op;
use App\Support\OpPlanSnapshot;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pushes a pre-edit snapshot of the op's plan onto the undo stack before a covered operative planning
 * edit — but only when the op is in planning AND the edit actually changed the plan. The signature
 * compare skips no-op reorders, idempotent re-runs, and validation-failure round-trips (a thrown
 * exception bypasses the post-capture entirely). Keeps the newest KEEP snapshots per op. Each snapshot is labelled
 * with what the edit was, so the Undo button can name what it will reverse.
 */
class CapturesOpUndo
{
    /** Undo depth retained per op. */
    private const KEEP = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $op = $request->route('op');
        if (! $op instanceof Op || $op->status !== 'planning') {
            return $next($request);
        }

        $before = OpPlanSnapshot::capture($op);
        $beforeSig = OpPlanSnapshot::signature($before);

        $response = $next($request);

        // record the pre-state only if the edit moved the plan (and didn't throw — exceptions never reach here)
        if (OpPlanSnapshot::signature(OpPlanSnapshot::capture($op)) !== $beforeSig) {
            $op->undoSnapshots()->create(['data' => $before, 'label' => self::describe($request, $op)]);

            // prune to the newest KEEP
            $keep = $op->undoSnapshots()->latest('id')->limit(self::KEEP)->pluck('id');
            $op->undoSnapshots()->whereNotIn('id', $keep)->delete();
        }

        return $response;
    }

    /**
     * The edit in a few words — "deleted portal “Lighthouse”". Runs after the edit, so an added portal or directive
     * already has its name; a deleted one is still readable from the route's bound model. Null for an unknown route.
     */
    private static function describe(Request $request, Op $op): ?string
    {
        $q = fn (?string $name) => '“'.Str::limit(trim((string) $name), 40).'”';
        $waypoint = $request->route('waypoint');
        $step = $request->route('step');
        $newest = fn ($relation) => $relation->reorder('id', 'desc')->first(); // the plan relations sort by seq

        $label = match ($request->route()?->getName()) {
            'ops.waypoints.store' => 'added portal '.$q($newest($op->waypoints())?->title),
            'ops.waypoints.update' => 'edited portal '.$q($waypoint?->title),
            'ops.waypoints.intel' => 'edited the intel for '.$q($waypoint?->title),
            'ops.waypoints.reorder' => 'reordered the portals',
            'ops.waypoints.clear' => 'deleted every portal',
            'ops.waypoints.destroy' => 'deleted portal '.$q($waypoint?->title),
            'ops.keys.needed' => 'set the keys needed for '.$q($waypoint?->title).' to '.(int) $request->input('keys_needed'),
            'ops.keys.clear' => 'cleared everyone’s held keys',
            'ops.steps.autofan' => 'auto-fan ('.$request->input('mode', 'both').')',
            'ops.plan.import' => 'imported a plan',
            'ops.steps.store' => (function () use ($op, $q, $newest) {
                $added = $newest($op->steps());

                return 'added directive '.$q($added?->label()).' at '.$q($op->waypoints()->whereKey($added?->op_waypoint_id)->value('title'));
            })(),
            'ops.steps.bulk' => 'added '.$q(ucfirst((string) $request->input('action'))).' to every portal',
            'ops.steps.update' => 'edited directive '.$q($step?->label()),
            'ops.steps.reorder' => 'reordered the directives',
            'ops.steps.destroy' => 'deleted directive '.$q($step?->label()),
            'ops.steps.clear' => 'cleared every directive',
            'ops.step-templates.apply' => 'applied template '.$q($request->route('template')?->name),
            default => null,
        };

        return $label === null ? null : Str::limit($label, 160);
    }
}
