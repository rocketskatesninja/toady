<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\Op;
use App\Models\OpParticipant;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ParticipantController extends Controller
{
    use AuthorizesOpAccess;

    /** Kick: remove from the op (they can rejoin via the link). */
    public function destroy(Request $request, Op $op, User $user): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $this->guardManageable($op, $request->user(), $user);

        $note = $this->removeFromOp($op, $user);

        return back()->with('success', "Removed {$user->callsign}.".$note);
    }

    /** Ban: remove + block rejoining (via link or callsign) until unbanned. */
    public function ban(Request $request, Op $op, User $user): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $this->guardManageable($op, $request->user(), $user);

        $note = $this->removeFromOp($op, $user);
        $op->bans()->firstOrCreate(['user_id' => $user->id], ['banned_by' => $request->user()->id]);

        return back()->with('success', "Banned {$user->callsign}.".$note);
    }

    /** Unban: lift a per-op ban so the agent can rejoin (via link) or be re-added by callsign. Idempotent. */
    public function unban(Request $request, Op $op, User $user): RedirectResponse
    {
        $this->requireOperative($op, $request->user());

        $op->bans()->where('user_id', $user->id)->delete();

        return back()->with('success', "Unbanned {$user->callsign}. They can rejoin the op.");
    }

    /** An agent removes themselves from an op and scrubs their footprint. The owner closes the op instead. */
    public function leave(Request $request, Op $op): RedirectResponse
    {
        $user = $request->user();
        $this->requireParticipant($op, $user);
        abort_if($op->owner_id === $user->id, 403, 'The owner closes the op rather than leaving it.');

        $this->removeFromOp($op, $user);

        return redirect()->route('dashboard')->with('success', "You left {$op->name}.");
    }

    /** Take a user off the op (kick, ban, leave): free their still-open directives back to "anyone" so they don't
     *  strand, drop their roster row, and scrub their personal op-local data — shared location + key reports.
     *  Chat/DMs stay as the op's record (purged when the op closes). Returns a "N directives freed up." note. */
    private function removeFromOp(Op $op, User $user): string
    {
        $freed = $op->steps()->where('assignee_id', $user->id)->where('done', false)->update(['assignee_id' => null]);
        $op->participants()->where('user_id', $user->id)->delete();
        $op->presence()->where('user_id', $user->id)->delete();
        $op->keyHoldings()->where('user_id', $user->id)->delete();

        return $freed ? " {$freed} directive".($freed === 1 ? '' : 's').' freed up.' : '';
    }

    /** The user's roster row on this op, or 404. */
    private function participantOf(Op $op, User $user): OpParticipant
    {
        return $op->participants()->where('user_id', $user->id)->firstOrFail();
    }

    /** Promote an agent to Operator so they can build + run the op. */
    public function promote(Request $request, Op $op, User $user): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $participant = $this->participantOf($op, $user);
        abort_if($participant->role === OpParticipant::ROLE_OPERATIVE, 422, 'Already an Operator.');

        $participant->update(['role' => OpParticipant::ROLE_OPERATIVE]);
        Notifier::send($user, 'join', 'Promoted to Operator · '.$op->name, 'You can now build and run this op.', '/ops/'.$op->public_id, $op->id);

        return back()->with('success', "{$user->callsign} is now an Operator.");
    }

    /** Owner demotes an Operator back to Agent. */
    public function demote(Request $request, Op $op, User $user): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        abort_unless($op->owner_id === $request->user()->id, 403, 'Only the op owner can change Operators.');
        abort_if($op->owner_id === $user->id, 403, 'The op owner cannot be demoted.');
        $participant = $this->participantOf($op, $user);
        abort_unless($participant->role === OpParticipant::ROLE_OPERATIVE, 422, 'Not an Operator.');

        $participant->update(['role' => OpParticipant::ROLE_AGENT]);
        Notifier::send($user, 'join', 'Role changed · '.$op->name, 'You are now an Agent on this op.', '/ops/'.$op->public_id, $op->id);

        return back()->with('success', "{$user->callsign} is now an Agent.");
    }

    /** Operator assigns (or clears) an agent's per-op colour — drives their map beacon/route + avatar ring. */
    public function setColor(Request $request, Op $op, User $user): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $participant = $this->participantOf($op, $user);
        $data = $request->validate([
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $participant->update(['color' => $data['color'] ?? null]);

        return back();
    }

    /**
     * Who an operator may demote / kick / ban:
     * - never the op owner (the creator is untouchable);
     * - a fellow Operator only if you are the owner;
     * - an Agent if you're any operator (requireOperative already enforced that).
     */
    private function guardManageable(Op $op, User $actor, User $target): void
    {
        abort_if($op->owner_id === $target->id, 403, 'The op owner cannot be removed.');
        if ($op->isOperative($target)) {
            abort_unless($op->owner_id === $actor->id, 403, 'Only the op owner can manage other Operators.');
        }
    }
}
