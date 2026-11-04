<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\HackTimer;
use App\Models\Op;
use App\Models\User;
use App\Support\Mechanics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hack timers — an agent's own cooldown/burnout clock per portal, plus the keys they farmed. Standalone
 * timers (/hacks) have no portal; op timers pick one of the op's portals, and their key stepper also moves
 * the agent's "keys mine" count for it. Every timer belongs to its agent alone (others get a 404).
 */
class HackTimerController extends Controller
{
    use AuthorizesOpAccess;

    /** A hack is always recorded, never refused (Niantic changes the numbers during events) — this only bounds the row. */
    private const MAX_WINDOW_HACKS = 64;

    public function index(Request $request): Response
    {
        $timers = HackTimer::where('user_id', $request->user()->id)
            ->with(['op:id,public_id,name', 'waypoint:id,title'])->latest('id')->get();

        return Inertia::render('Hacks', ['timers' => $timers->map->toPayload()]);
    }

    /** A standalone timer — no portal; an optional nickname, else the next free "Timer N". */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['label' => ['nullable', 'string', 'max:80'], 'friendly' => ['boolean']]);

        HackTimer::create([
            'user_id' => $user->id,
            'label' => trim($data['label'] ?? '') ?: $this->autoLabel($user),
            'friendly' => $data['friendly'] ?? false,
        ]);

        return back();
    }

    /** An op timer on one of the op's portals — one per agent per portal (picking it again reuses it). */
    public function storeForOp(Request $request, Op $op): RedirectResponse
    {
        $user = $request->user();
        $this->requireOpTool($op, $user);
        $data = $request->validate([
            'op_waypoint_id' => ['required', 'integer', $this->waypointOf($op)],
            'friendly' => ['boolean'],
        ]);

        // only a portal this viewer can see (a hidden op redacts the ones ahead of the agent)
        [$visible] = $op->visiblePlan($op->isOperative($user));
        $waypoint = $visible->find($data['op_waypoint_id']) ?? abort(404);
        HackTimer::firstOrCreate(
            ['user_id' => $user->id, 'op_waypoint_id' => $waypoint->id],
            ['op_id' => $op->id, 'label' => mb_substr($waypoint->title ?: 'Portal', 0, 80), 'friendly' => $data['friendly'] ?? false],
        );

        return back();
    }

    /** Nickname (standalone only — an op timer shows its portal's name) and portal alignment. */
    public function update(Request $request, HackTimer $timer): RedirectResponse
    {
        $this->own($timer, $request);
        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'friendly' => ['sometimes', 'boolean'],
        ]);

        if ($timer->op_waypoint_id) {
            unset($data['label']); // an op timer is named by its portal
        } elseif (array_key_exists('label', $data)) {
            $data['label'] = trim($data['label'] ?? '') ?: $this->autoLabel($request->user(), $timer->id);
        }
        $timer->update($data);

        return back();
    }

    /** Set one of the portal's 4 mod slots (null empties it) — adding a Heat Sink resets the clock, see setMod(). */
    public function slot(Request $request, HackTimer $timer): RedirectResponse
    {
        $this->own($timer, $request);
        $data = $request->validate([
            'slot' => ['required', 'integer', 'between:0,'.(Mechanics::MOD_SLOTS - 1)],
            'mod' => ['nullable', Rule::in(HackTimer::modKeys())],
        ]);

        $timer->setMod($data['slot'], $data['mod'] ?? null);

        return back();
    }

    public function hack(Request $request, HackTimer $timer): RedirectResponse
    {
        $this->own($timer, $request);
        $hacks = $timer->windowHacks(); // a stale window (4 h past its first hack) starts over here
        abort_if(count($hacks) >= self::MAX_WINDOW_HACKS, 422, 'That’s more hacks than any portal allows.');
        $timer->update(['hacks' => [...$hacks, now()->toIso8601String()]]);

        return back();
    }

    /** Take back the last hack (a mis-tap). */
    public function undo(Request $request, HackTimer $timer): RedirectResponse
    {
        $this->own($timer, $request);
        $timer->update(['hacks' => array_slice($timer->windowHacks(), 0, -1)]);

        return back();
    }

    /** ±1 key farmed. On an op timer the same key moves "keys mine" for that portal (and can fire "fully keyed"). */
    public function keys(Request $request, HackTimer $timer): RedirectResponse
    {
        $this->own($timer, $request);
        $delta = (int) $request->validate(['delta' => ['required', 'integer', Rule::in([-1, 1])]])['delta'];
        if ($delta < 0 && $timer->keys === 0) {
            return back();
        }
        $timer->update(['keys' => $timer->keys + $delta]);

        $user = $request->user();
        [$op, $waypoint] = [$timer->op, $timer->waypoint];
        if ($op && $waypoint && $this->canUseOpTool($op, $user)) {
            $op->setHeldKeys($user, $waypoint, $op->heldKeys($user, $waypoint) + $delta); // setHeldKeys clears at ≤ 0
        }

        return back();
    }

    public function destroy(Request $request, HackTimer $timer): RedirectResponse
    {
        $this->own($timer, $request);
        $timer->delete();

        return back();
    }

    private function own(HackTimer $timer, Request $request): void
    {
        abort_unless($timer->user_id === $request->user()->id, 404);
    }

    /** The op tool is for members — and agents only once the op is live (a planning op is hidden from them). */
    private function canUseOpTool(Op $op, User $user): bool
    {
        return $op->roleFor($user) !== null && ! $op->isDraftFor($user);
    }

    private function requireOpTool(Op $op, User $user): void
    {
        $this->requireParticipant($op, $user);
        abort_if($op->isDraftFor($user), 403, 'This op isn’t live yet.');
    }

    /** The lowest "Timer N" this agent isn't already using — stable, so names don't shift when one is deleted. */
    private function autoLabel(User $user, ?int $exceptId = null): string
    {
        $taken = HackTimer::where('user_id', $user->id)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->pluck('label')->flip();
        for ($n = 1; isset($taken["Timer $n"]); $n++);

        return "Timer $n";
    }
}
