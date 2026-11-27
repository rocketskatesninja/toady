<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Op;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared op authorization + IDOR guards. Every op-scoped action gates on one of:
 *   - operative (owner or operative-role) — can manage the op, or
 *   - participant (any role, incl. operative) — can read/act inside the op.
 * Nested route models ({waypoint},{step},{message}) must be confirmed to belong to the {op}.
 */
trait AuthorizesOpAccess
{
    /** Manage actions: owner or operative-role only. */
    protected function requireOperative(Op $op, ?User $user): void
    {
        abort_unless($op->isOperative($user), 403);
    }

    /**
     * In-op actions: any member of the op (operative included). A non-member gets 404, not 403 —
     * deliberately indistinguishable from an op that doesn't exist, so a shared/guessed op URL never
     * confirms the op is real. GET requests flow through the soft-landing handler to the dashboard.
     */
    protected function requireParticipant(Op $op, ?User $user): void
    {
        abort_unless($op->roleFor($user) !== null, 404);
    }

    /**
     * In-op actions on the PLAN (read it, check things off, ask the AI about it): a member, and not an agent of
     * an op that's still a draft — a planning op is hidden from its agents on every path, not just the op page.
     */
    protected function requireLive(Op $op, ?User $user): void
    {
        $this->requireParticipant($op, $user);
        abort_if($op->isDraftFor($user), 404);
    }

    /** A hidden-type op only shows agents the plan up to the current front — the same rule for acting on a
     *  waypoint by id as for seeing it (see Op::visiblePlan). */
    protected function requireVisibleWaypoint(Op $op, ?User $user, ?int $waypointId): void
    {
        if ($waypointId === null) {
            return;
        }
        [, , $visibleIds] = $op->visiblePlan($op->isOperative($user));
        abort_if($visibleIds !== null && ! $visibleIds->contains($waypointId), 404);
    }

    /** Validation: a location in THIS op — so a request can't point a directive at another op's portal. */
    protected function waypointOf(Op $op): Exists
    {
        return Rule::exists('op_waypoints', 'id')->where('op_id', $op->id);
    }

    /** Validation: a user on THIS op's roster — so a directive can't be assigned to (or push-spam) anyone else. */
    protected function memberOf(Op $op): Exists
    {
        return Rule::exists('op_participants', 'user_id')->where('op_id', $op->id);
    }

    /** Structural plan edits (waypoints/directives): an operator, and only while the op is still in planning —
     *  the plan is locked once it goes active. */
    protected function requireEditable(Op $op, ?User $user): void
    {
        $this->requireOperative($op, $user);
        abort_if($op->status !== 'planning', 409, 'The plan is locked while the op is active. Flip it back to planning to edit.');
    }
}
