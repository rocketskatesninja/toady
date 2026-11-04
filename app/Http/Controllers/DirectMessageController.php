<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\DirectMessage;
use App\Models\Op;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 1:1 direct messages, scoped to an op (purged with it). Both parties must be participants.
 */
class DirectMessageController extends Controller
{
    use AuthorizesOpAccess;

    /** Both the actor and the other party must be members of this op. */
    private function ctx(Request $request, Op $op, User $other): int
    {
        $this->requireParticipant($op, $request->user());
        abort_unless($op->roleFor($other) !== null, 404);

        return $request->user()->id;
    }

    private function payload(DirectMessage $m, int $me): array
    {
        return ['id' => $m->id, 'mine' => $m->sender_id === $me, 'body' => $m->body, 'at' => $m->created_at->toIso8601String()];
    }

    public function index(Request $request, Op $op, User $user): JsonResponse
    {
        $me = $this->ctx($request, $op, $user);
        $other = $user->id;

        $messages = DirectMessage::where('op_id', $op->id)
            ->where(function ($q) use ($me, $other) {
                $q->where(fn ($a) => $a->where('sender_id', $me)->where('recipient_id', $other))
                    ->orWhere(fn ($b) => $b->where('sender_id', $other)->where('recipient_id', $me));
            })
            ->orderBy('id')->get()
            ->map(fn (DirectMessage $m) => $this->payload($m, $me));

        return response()->json($messages);
    }

    public function store(Request $request, Op $op, User $user): JsonResponse
    {
        $this->ctx($request, $op, $user);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $m = DirectMessage::create([
            'op_id' => $op->id,
            'sender_id' => $request->user()->id,
            'recipient_id' => $user->id,
            'body' => $data['body'],
        ]);

        Notifier::send($user, 'dm', 'Direct message from '.$request->user()->callsign.' · '.$op->name, $m->body,
            '/ops/'.$op->public_id.'?view=dms&dm='.$request->user()->id, $op->id,
            tag: 'dm-'.$op->id.'-'.$request->user()->id);

        return response()->json($this->payload($m, $request->user()->id));
    }
}
