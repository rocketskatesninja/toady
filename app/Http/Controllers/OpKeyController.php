<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\Op;
use App\Models\OpWaypoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OpKeyController extends Controller
{
    use AuthorizesOpAccess;

    /** An agent records how many keys THEY hold for a portal (any status — it's field logistics). */
    public function update(Request $request, Op $op, OpWaypoint $waypoint): RedirectResponse
    {
        $user = $request->user();
        $this->requireLive($op, $user);
        $this->requireVisibleWaypoint($op, $user, $waypoint->id);

        $op->setHeldKeys($user, $waypoint, (int) $request->validate(['qty' => ['required', 'integer', 'min:0', 'max:9999']])['qty']);

        return back();
    }

    /** The operative wipes every agent's held-key counts for the op (field-logistics reset — targets stay). */
    public function clearHeld(Request $request, Op $op): RedirectResponse
    {
        $this->requireEditable($op, $request->user());

        $n = $op->keyHoldings()->count();
        $op->keyHoldings()->delete();

        return back()->with('success', $n
            ? 'Cleared held-key counts for '.$n.' entr'.($n === 1 ? 'y' : 'ies').' — key targets kept.'
            : 'No held keys to clear.');
    }

    /** The operative sets how many keys the plan needs for a portal. */
    public function setNeeded(Request $request, Op $op, OpWaypoint $waypoint): RedirectResponse
    {
        $this->requireEditable($op, $request->user()); // the plan's key target is locked once the op is active

        $waypoint->update([
            'keys_needed' => (int) $request->validate(['keys_needed' => ['required', 'integer', 'min:0', 'max:9999']])['keys_needed'],
        ]);

        return back();
    }
}
