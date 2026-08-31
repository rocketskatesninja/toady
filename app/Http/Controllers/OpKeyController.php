<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\Op;
use App\Models\OpWaypoint;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OpKeyController extends Controller
{
    use AuthorizesOpAccess;

    /** An agent records how many keys THEY hold for a portal (any status — it's field logistics). */
    public function update(Request $request, Op $op, OpWaypoint $waypoint): RedirectResponse
    {
        $user = $request->user();
        $this->requireParticipant($op, $user);
        $this->requireBelongsToOp($op, $waypoint);

        $qty = (int) $request->validate(['qty' => ['required', 'integer', 'min:0', 'max:9999']])['qty'];

        $needed = (int) $waypoint->keys_needed;
        $heldBefore = (int) $op->keyHoldings()->where('op_waypoint_id', $waypoint->id)->sum('qty');

        if ($qty === 0) {
            $op->keyHoldings()->where('op_waypoint_id', $waypoint->id)->where('user_id', $user->id)->delete();
        } else {
            $op->keyHoldings()->updateOrCreate(
                ['op_waypoint_id' => $waypoint->id, 'user_id' => $user->id],
                ['qty' => $qty],
            );
        }

        // Notify the operative(s) once — the moment this portal reaches its needed key count (short → met).
        if ($needed > 0) {
            $heldAfter = (int) $op->keyHoldings()->where('op_waypoint_id', $waypoint->id)->sum('qty');
            if ($heldBefore < $needed && $heldAfter >= $needed) {
                $op->operativeRecipients()->reject(fn (User $u) => $u->id === $user->id)
                    ->each(fn (User $u) => Notifier::send($u, 'keys',
                        '🔑 '.($waypoint->title ?: 'Portal').' fully keyed',
                        "{$heldAfter}/{$needed} keys in — this portal’s ready to link & field.", '/ops/'.$op->public_id.'?view=plan', $op->id));
            }
        }

        return back();
    }

    /** The operative sets how many keys the plan needs for a portal. */
    public function setNeeded(Request $request, Op $op, OpWaypoint $waypoint): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $this->requirePlanning($op); // the plan's key target is locked once the op is active
        $this->requireBelongsToOp($op, $waypoint);

        $waypoint->update([
            'keys_needed' => (int) $request->validate(['keys_needed' => ['required', 'integer', 'min:0', 'max:9999']])['keys_needed'],
        ]);

        return back();
    }
}
