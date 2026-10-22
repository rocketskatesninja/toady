<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Op;
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

    /**
     * Resolve the ?op= filter (an op's public_id) to its internal FK. Null when absent or unknown,
     * which leaves the feed unscoped — matching the old integer(0) → no-filter behaviour.
     */
    private function scopedOpId(Request $request): ?int
    {
        if (! $request->filled('op')) {
            return null;
        }

        return Op::where('public_id', $request->string('op'))->value('id');
    }

    /** Light JSON feed for the header bell badge + the op-scoped widget (?op=). */
    public function feed(Request $request): JsonResponse
    {
        $uid = $request->user()->id;
        $q = Notification::where('user_id', $uid);
        if ($op = $this->scopedOpId($request)) {
            $q->where('op_id', $op);
        }

        return response()->json([
            'unread' => Notification::where('user_id', $uid)->whereNull('read_at')->count(),
            'items' => $q->latest('id')->limit(30)->get()->map(fn (Notification $n) => $this->map($n)),
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
        $q = Notification::where('user_id', $request->user()->id)->whereNull('read_at');
        if ($op = $this->scopedOpId($request)) {
            $q->where('op_id', $op);
        }
        $q->update(['read_at' => now()]);

        return back();
    }

    /** Delete the viewer's notifications (op-scoped when ?op= is given) — the panel's "clear". */
    public function clear(Request $request): HttpResponse
    {
        $q = Notification::where('user_id', $request->user()->id);
        if ($op = $this->scopedOpId($request)) {
            $q->where('op_id', $op);
        }
        $q->delete();

        return response()->noContent();
    }
}
