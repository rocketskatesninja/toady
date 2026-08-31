<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\Op;
use App\Models\OpMessage;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    use AuthorizesOpAccess;

    public function index(Request $request, Op $op): JsonResponse
    {
        $this->requireParticipant($op, $request->user());

        $messages = $op->messages()->with('user:id,callsign,faction')
            ->latest('id')->limit(100)->get()->reverse()->values()
            ->map(fn (OpMessage $m) => $this->payload($m));

        return response()->json($messages);
    }

    public function store(Request $request, Op $op): JsonResponse
    {
        $this->requireParticipant($op, $request->user());
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $user = $request->user();
        $m = $op->messages()->create(['user_id' => $user->id, 'body' => $data['body']]);
        $m->load('user:id,callsign,faction');

        // everyone in the op except the sender — the pool both mentions and broadcasts draw from
        $others = $op->participants()->with('user')->get()->filter(fn ($p) => $p->user && $p->user_id !== $user->id);
        $link = '/ops/'.$op->public_id.'?view=dms&tab=op';
        $ping = fn ($p, $title) => Notifier::send($p->user, 'mention', $title, $m->body, $link, $op->id, tag: 'mention-'.$op->id);

        // @all / @op — operatives broadcast to the whole op at once; otherwise notify @callsign mentions
        if ($op->isOperative($user) && preg_match('/@(all|op)(?![\p{L}\p{N}_-])/iu', $data['body'])) {
            $others->each(fn ($p) => $ping($p, $user->callsign.' broadcast to the op · '.$op->name));
        } else {
            preg_match_all('/@([\p{L}\p{N}_-]{2,32})/u', $data['body'], $mm);
            if (! empty($mm[1])) {
                $names = array_map('mb_strtolower', $mm[1]);
                $others->filter(fn ($p) => in_array(mb_strtolower((string) $p->user->callsign), $names, true))
                    ->each(fn ($p) => $ping($p, $user->callsign.' mentioned you in comms · '.$op->name));
            }
        }

        return response()->json($this->payload($m));
    }

    public function destroy(Request $request, Op $op, OpMessage $message): JsonResponse
    {
        $this->requireParticipant($op, $request->user());
        $this->requireBelongsToOp($op, $message);
        abort_unless($message->user_id === $request->user()->id || $op->isOperative($request->user()), 403);

        $message->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array<string,mixed> */
    private function payload(OpMessage $m): array
    {
        return [
            'id' => $m->id, 'user_id' => $m->user_id,
            'user' => $m->user->callsign, 'faction' => $m->user->faction,
            'body' => $m->body, 'at' => $m->created_at->toIso8601String(),
        ];
    }
}
