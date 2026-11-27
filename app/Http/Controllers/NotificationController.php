<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Op;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class NotificationController extends Controller
{
    private function map(Notification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'url' => $n->url,
            'op_id' => $n->op_id,
            'read' => $n->read_at !== null,
            'at' => $n->created_at?->toIso8601String(),
        ];
    }

    /** The viewer's notifications, narrowed to one op when ?op= is given (by public id). An unknown or closed op matches
     *  nothing — never "all" (a clear from a closed op's page used to wipe every notification). Shared by the feed,
     *  mark-all-read, and clear. */
    private function mine(Request $request): Builder
    {
        $mine = Notification::where('user_id', $request->user()->id);
        if ($request->filled('op')) {
            $mine->where('op_id', Op::where('public_id', $request->string('op'))->value('id') ?? 0);
        }

        return $mine;
    }

    /** Light JSON feed for the header bell badge + the op-scoped widget (?op=). */
    public function feed(Request $request): JsonResponse
    {
        return response()->json([
            'unread' => Notification::where('user_id', $request->user()->id)->whereNull('read_at')->count(), // the bell: every op
            'items' => $this->mine($request)->latest('id')->limit(30)->get()->map(fn (Notification $n) => $this->map($n)),
        ]);
    }

    public function read(Request $request, Notification $notification): HttpResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        if (! $notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->noContent();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->mine($request)->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }

    /** Delete the viewer's notifications (op-scoped when ?op= is given) — the panel's "clear". */
    public function clear(Request $request): HttpResponse
    {
        $this->mine($request)->delete();

        return response()->noContent();
    }
}
