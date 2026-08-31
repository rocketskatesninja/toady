<?php

namespace App\Http\Middleware;

use App\Models\Op;
use App\Support\OpPlanSnapshot;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pushes a pre-edit snapshot of the op's plan onto the undo stack before a covered operative planning
 * edit — but only when the op is in planning AND the edit actually changed the plan. The signature
 * compare skips no-op reorders, idempotent re-runs, and validation-failure round-trips (a thrown
 * exception bypasses the post-capture entirely). Keeps the newest KEEP snapshots per op.
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
            $op->undoSnapshots()->create(['data' => $before]);

            // prune to the newest KEEP
            $keep = $op->undoSnapshots()->latest('id')->limit(self::KEEP)->pluck('id');
            $op->undoSnapshots()->whereNotIn('id', $keep)->delete();
        }

        return $response;
    }
}
