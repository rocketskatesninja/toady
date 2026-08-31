<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\Op;
use App\Support\OpPlanSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pops the op's undo stack: restore the newest pre-edit snapshot, then discard it (a true pop — no
 * redo). Operative-only and planning-only, mirroring every edit that pushes onto the stack. This
 * route is NOT wrapped in CapturesOpUndo, so undo itself never records a snapshot.
 */
class OpUndoController extends Controller
{
    use AuthorizesOpAccess;

    public function undo(Request $request, Op $op): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $this->requirePlanning($op);

        $snap = $op->undoSnapshots()->latest('id')->first();
        abort_if($snap === null, 422, 'Nothing to undo.');

        OpPlanSnapshot::restore($op, $snap->data);
        $snap->delete();

        return back()->with('success', 'Undid the last plan change.');
    }
}
