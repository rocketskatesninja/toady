<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\Op;
use App\Models\OpStep;
use App\Models\User;
use App\Support\Fielding;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class OpStepController extends Controller
{
    use AuthorizesOpAccess;

    /** Niantic-style task actions. */
    public const ACTIONS = ['hack', 'frack', 'capture', 'destroy', 'ada', 'jarvis', 'deploy', 'link', 'mod', 'farm keys', 'recharge', 'photo', 'passphrase', 'move', 'note'];

    public function store(Request $request, Op $op): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $this->requirePlanning($op);
        $data = $request->validate([
            // a directive needs an objective and/or a comment (at least one)
            'text' => ['nullable', 'required_without:action', 'string', 'max:240'],
            'action' => ['nullable', 'required_without:text', Rule::in(self::ACTIONS)],
            'phase' => ['sometimes', Rule::in(['prep', 'run'])],
            // a directive always belongs to a location card
            'op_waypoint_id' => ['required', Rule::exists('op_waypoints', 'id')->where('op_id', $op->id)],
            'assignee_id' => ['sometimes', 'nullable', Rule::exists('op_participants', 'user_id')->where('op_id', $op->id)],
            // objective-specific: link → target waypoint id(s); mod → the mod name
            'links' => ['sometimes', 'array'],
            'links.*' => [Rule::exists('op_waypoints', 'id')->where('op_id', $op->id)],
            'mods' => ['sometimes', 'nullable', 'string', 'max:64'],
            'qty' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'], // e.g. farm keys → how many
        ]);
        $seq = ((int) $op->steps()->max('seq')) + 1;
        $op->steps()->create([
            'phase' => $data['phase'] ?? 'run',
            'seq' => $seq,
            'text' => $data['text'] ?? null,
            'action' => $data['action'] ?? null,
            'op_waypoint_id' => $data['op_waypoint_id'],
            'assignee_id' => $data['assignee_id'] ?? null,
            'links' => $data['links'] ?? null,
            'mods' => $data['mods'] ?? null,
            'qty' => $data['qty'] ?? null,
        ]);

        return back();
    }

    /**
     * Add one action-directive to EVERY portal in the op at once — the Plan panel's "to all" quick-add.
     * Idempotent: skips a portal that already has this exact action + assignee, so re-clicking never doubles up.
     */
    public function bulk(Request $request, Op $op): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $this->requirePlanning($op);
        $data = $request->validate([
            'action' => ['required', Rule::in(self::ACTIONS)],
            'assignee_id' => ['sometimes', 'nullable', Rule::exists('op_participants', 'user_id')->where('op_id', $op->id)],
            'links' => ['sometimes', 'array'], // link → the single target every portal throws to
            'links.*' => [Rule::exists('op_waypoints', 'id')->where('op_id', $op->id)],
            'mods' => ['sometimes', 'nullable', 'string', 'max:64'],
            'qty' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
        ]);
        $action = $data['action'];
        $assignee = $data['assignee_id'] ?? null;
        $links = $action === 'link' ? array_values(array_filter(array_map('intval', $data['links'] ?? []))) : null;
        $target = $links[0] ?? null;

        // portals that already have this exact directive (same action + assignee, and for link the same target)
        $already = $op->steps()->where('action', $action)->where('assignee_id', $assignee)
            ->get(['op_waypoint_id', 'links'])
            ->filter(fn ($s) => $action !== 'link' || (int) ($s->links[0] ?? 0) === (int) $target)
            ->pluck('op_waypoint_id')->flip();

        $seq = (int) $op->steps()->max('seq');
        foreach ($op->waypoints()->orderBy('seq')->pluck('id') as $wpId) {
            if ($action === 'link' && (int) $wpId === (int) $target) {
                continue; // a portal can't link to itself
            }
            if ($already->has($wpId)) {
                continue;
            }
            $op->steps()->create([
                'phase' => 'run', 'seq' => ++$seq, 'action' => $action,
                'op_waypoint_id' => $wpId, 'assignee_id' => $assignee,
                'links' => $links, 'mods' => $data['mods'] ?? null, 'qty' => $data['qty'] ?? null,
            ]);
        }

        return back();
    }

    /** Auto-fan — the one fan action. Runs the fan geometry once from the placed anchor(s) and lays down the
     *  plan; `mode` picks what to generate:
     *    - 'links' → the fan link directives, one per single link, innermost-out throw order (you can't link
     *                from inside a field): the base anchor link, then each spine to both anchors + the
     *                previous spine. Also reorders the waypoints into throw order.
     *    - 'keys'  → one "farm keys" directive per location, qty = its key requirement.
     *    - 'both'  → both of the above (default).
     *  Either way every location's key target (keys_needed) is (re)computed and persisted, so the Recon key
     *  overview stays in sync. Optionally hands every generated directive to one agent. Idempotent: a re-run
     *  replaces only the directive kinds it generates (manual links/farm-keys of that kind are overwritten). */
    public function autoFan(Request $request, Op $op): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $this->requirePlanning($op);
        $data = $request->validate([
            'mode' => ['sometimes', Rule::in(['links', 'keys', 'both'])],
            'assignee_id' => ['nullable', Rule::exists('op_participants', 'user_id')->where('op_id', $op->id)],
        ]);
        $mode = $data['mode'] ?? 'both';
        $assignee = $data['assignee_id'] ?? null;

        $fan = $this->fanFromAnchors($op); // link plan + persisted key targets, derived from the anchors alone
        $msg = [];

        if ($mode !== 'keys') {
            $op->steps()->where('action', 'link')->delete(); // idempotent re-run (also clears manual links)
            $seq = (int) $op->steps()->max('seq');
            foreach ($fan['plan'] as $link) {
                $op->steps()->create([
                    'phase' => 'run', 'seq' => ++$seq, 'action' => 'link',
                    'op_waypoint_id' => $link['origin'], 'links' => [$link['target']], 'assignee_id' => $assignee,
                ]);
            }
            // put the waypoints in fielding order so the Directives/Recon lists read top-to-bottom in throw order
            $this->reorderWaypoints($op, $fan['order']);
            $msg[] = count($fan['plan']).' links · ~'.$fan['fields'].' fields';
        }

        if ($mode !== 'links') {
            $op->steps()->where('action', 'farm keys')->delete(); // idempotent re-run
            $seq = (int) $op->steps()->max('seq');
            $targets = $op->waypoints()->where('keys_needed', '>', 0)->orderBy('seq')->get(['id', 'keys_needed']);
            foreach ($targets as $w) {
                $op->steps()->create([
                    'phase' => 'run', 'seq' => ++$seq, 'action' => 'farm keys',
                    'op_waypoint_id' => $w->id, 'qty' => $w->keys_needed, 'assignee_id' => $assignee,
                ]);
            }
            $msg[] = $targets->count().' farm-key directive'.($targets->count() === 1 ? '' : 's');
        }

        return back()->with('success', implode(' · ', $msg).' · key targets set.');
    }

    /** Shared fan geometry for auto-fan. From the placed anchors — 2 anchors for a classic
     *  double-anchor fan, or 1 for a single-anchor fan (every other placed portal becomes a spine) — build
     *  the fan link plan and each portal's key requirement (inbound links + 1 to recharge) and persist
     *  keys_needed so the keys overview and farm quantities stay in sync.
     *
     * @return array{plan: list<array{origin:int,target:int}>, fields: int, order: list<int>}
     */
    private function fanFromAnchors(Op $op): array
    {
        $placed = fn (string $role) => $op->waypoints()->where('role', $role)
            ->whereNotNull('lat')->whereNotNull('lng')->orderBy('seq')->get(['id', 'lat', 'lng'])
            ->map(fn ($w) => ['id' => $w->id, 'lat' => (float) $w->lat, 'lng' => (float) $w->lng])->all();

        $anchors = $placed('anchor');
        abort_unless(count($anchors) === 1 || count($anchors) === 2, 422, 'Set 1 or 2 placed anchors first.');

        // you only tag the anchor(s) — every OTHER placed portal becomes a spine in the fan automatically
        $op->waypoints()->where('role', '!=', 'anchor')->whereNotNull('lat')->whereNotNull('lng')->update(['role' => 'spine']);

        $spines = $placed('spine');

        if (count($anchors) === 2) {
            abort_unless(count($spines) >= 1, 422, 'Add at least one other placed portal between the anchors.');
            $spines = Fielding::fanOrder($anchors, $spines);       // innermost -> outermost
            $plan = Fielding::planFan($anchors, $spines);
            $fields = 1 + 2 * max(0, count($spines) - 1);
        } else {
            // one anchor → single-anchor fan (needs 2 spines to make a field)
            abort_unless(count($spines) >= 2, 422, 'Add at least two other placed portals to fan from the anchor.');
            $spines = Fielding::singleFanOrder($anchors[0], $spines); // sweep order
            $plan = Fielding::planSingleFan($anchors[0], $spines);
            $fields = max(0, count($spines) - 1);
        }

        // waypoint visit/throw order: anchors first, then the now-sorted spines
        $order = array_merge(array_column($anchors, 'id'), array_column($spines, 'id'));

        // keys = inbound links + 1 to recharge, for every anchor and spine (the apex spine has no inbound link)
        $keys = [];
        foreach ($plan as $link) {
            $keys[$link['target']] = ($keys[$link['target']] ?? 0) + 1;
        }
        foreach (array_merge($anchors, $spines) as $w) {
            $op->waypoints()->where('id', $w['id'])->update(['keys_needed' => ($keys[$w['id']] ?? 0) + 1]);
        }

        return ['plan' => $plan, 'fields' => $fields, 'order' => $order];
    }

    /** Renumber the op's waypoints so every list reads in throw order: the fielded anchors + spines first
     *  (innermost out), then any other (unplaced/generic) waypoints keeping their existing relative order.
     *  Every view orders by waypoint seq, so this puts the Directives/Recon lists in the order you execute. */
    private function reorderWaypoints(Op $op, array $orderedIds): void
    {
        $seq = 0;
        foreach ($orderedIds as $id) {
            $op->waypoints()->where('id', $id)->update(['seq' => ++$seq]);
        }
        foreach ($op->waypoints()->whereNotIn('id', $orderedIds)->orderBy('seq')->pluck('id') as $id) {
            $op->waypoints()->where('id', $id)->update(['seq' => ++$seq]);
        }
    }

    public function update(Request $request, Op $op, OpStep $step): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $this->requirePlanning($op);
        $this->requireBelongsToOp($op, $step);

        $data = $request->validate([
            'text' => ['sometimes', 'nullable', 'string', 'max:240'],
            'action' => ['sometimes', 'nullable', Rule::in(self::ACTIONS)],
            'op_waypoint_id' => ['sometimes', 'nullable', Rule::exists('op_waypoints', 'id')->where('op_id', $op->id)],
            // assignee must be a member of this op (prevents assigning to / push-spamming arbitrary users)
            'assignee_id' => ['sometimes', 'nullable', Rule::exists('op_participants', 'user_id')->where('op_id', $op->id)],
            'resos' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mods' => ['sometimes', 'nullable', 'string', 'max:120'],
            'links' => ['sometimes', 'nullable', 'array'],
            'links.*' => [Rule::exists('op_waypoints', 'id')->where('op_id', $op->id)],
            'qty' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $wasAssignee = $step->assignee_id;
        $step->update($data);

        // Buzz a freshly-assigned agent (not yourself).
        if (array_key_exists('assignee_id', $data) && $step->assignee_id
            && $step->assignee_id !== $wasAssignee && $step->assignee_id !== $request->user()->id
            && ($assignee = User::find($step->assignee_id))) {
            $what = $step->text ?: ucfirst($step->action ?? 'task');
            // assigned ≠ go: spell out when they're actually clear to act, based on the op's state/type
            $when = $op->status === 'planning'
                ? 'Op is still being planned — hold until it goes live.'
                : (in_array($op->type, ['visible', 'hidden'], true)
                    ? 'Get set, but don’t start — you’ll get a “your turn” alert when it’s time.'
                    : 'The op is live — act on it whenever you’re ready.');
            Notifier::send($assignee, 'task', 'Assigned to you · '.$op->name, $what.'. '.$when,
                '/ops/'.$op->public_id.'?step='.$step->id, $op->id, tag: 'task-'.$op->id);
        }

        return back();
    }

    /** Any participant can check a step off. */
    public function toggle(Request $request, Op $op, OpStep $step): Response|RedirectResponse
    {
        $this->requireParticipant($op, $request->user());
        $this->requireBelongsToOp($op, $step);

        // An assigned directive can only be checked off by its assignee (the operative may override);
        // unassigned ("anyone") directives stay open to any participant.
        abort_if($step->assignee_id && $step->assignee_id !== $request->user()->id && ! $op->isOperative($request->user()),
            403, 'Only the assigned agent can complete this directive.');

        $done = $request->boolean('done', ! $step->done);
        $wasDone = (bool) $step->done;

        // Sequential ops run directive-by-directive in order (waypoint, then position within it): an agent
        // can only complete the next open directive — everything after it waits. Operatives may override.
        if ($done && in_array($op->type, ['visible', 'hidden'], true) && ! $op->isOperative($request->user())) {
            $wpSeq = $op->waypoints()->pluck('seq', 'id');
            $ordered = $op->steps()->get(['id', 'op_waypoint_id', 'seq', 'done'])
                ->sortBy(fn ($s) => sprintf('%06d.%06d', $wpSeq[$s->op_waypoint_id] ?? 999999, $s->seq))
                ->values();
            $frontIdx = $ordered->search(fn ($s) => ! $s->done);
            $stepIdx = $ordered->search(fn ($s) => $s->id === $step->id);
            abort_if($frontIdx !== false && $stepIdx > $frontIdx, 422, 'This op runs in sequence — finish the earlier directives first.');
        }

        $step->update([
            'done' => $done,
            'done_by' => $done ? $request->user()->id : null,
            'done_at' => $done ? now() : null,
        ]);

        // Tell the operative(s) when an agent finishes a directive — but not when they finish their own.
        if ($done && ! $wasDone) {
            $label = $step->text ?: ucfirst($step->action ?? 'task');
            $where = $step->op_waypoint_id ? $op->waypoints()->whereKey($step->op_waypoint_id)->value('title') : null;
            $doneBody = $label.($where ? ' · '.$where : '');
            $op->operativeRecipients()->reject(fn (User $u) => $u->id === $request->user()->id)
                ->each(fn (User $u) => Notifier::send($u, 'done', '✓ '.$request->user()->callsign.' finished a directive', $doneBody,
                    '/ops/'.$op->public_id.'?step='.$step->id, $op->id));

            // Ordered missions: pass the baton — tell the next assigned agent it's their turn.
            if ($op->type !== 'any_order') {
                $next = $op->steps()->where('done', false)->whereNotNull('assignee_id')->orderBy('seq')->first();
                if ($next && $next->assignee_id !== $request->user()->id && ($agent = User::find($next->assignee_id))) {
                    Notifier::send($agent, 'turn', 'Your turn · '.$op->name, ($next->text ?: ucfirst($next->action ?? 'task')).' — you’re cleared to go now. Check it off when it’s done.',
                        '/ops/'.$op->public_id.'?step='.$next->id, $op->id, tag: 'turn-'.$op->id);
                }
            }
        }

        // Auto-complete: when the last directive is checked off on an ACTIVE op, flip it to complete and push
        // the team. (Planning stays a draft; a manual "complete" is left alone, so reopening a directive never
        // silently undoes the operator's call.)
        if ($op->status === 'active' && $op->steps()->count() > 0 && ! $op->steps()->where('done', false)->exists()) {
            $op->update(['status' => 'complete']);
            $op->notifyComplete($request->user()->id, 'Every directive is done — the op is complete. Nice work out there.');
        }

        if ($request->wantsJson()) {
            return response()->noContent();
        }

        return back();
    }

    public function reorder(Request $request, Op $op): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $this->requirePlanning($op);
        // reorder by id across the location's whole directive list — the UI shows every directive together
        // regardless of phase, so seq must be updated the same way (filtering by phase left farm-keys stuck).
        $data = $request->validate(['order' => ['array', 'max:1000'], 'order.*' => ['integer']]);
        foreach ($data['order'] ?? [] as $i => $id) {
            $op->steps()->where('id', $id)->update(['seq' => $i + 1]);
        }

        return back();
    }

    public function destroy(Request $request, Op $op, OpStep $step): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $this->requirePlanning($op);
        $this->requireBelongsToOp($op, $step);
        $step->delete();

        return back();
    }

    /** Wipe every directive across all locations — the locations themselves stay. Undoable (CapturesOpUndo). */
    public function clearAll(Request $request, Op $op): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $this->requirePlanning($op);
        $n = $op->steps()->count();
        $op->steps()->delete();

        return back()->with('success', $n ? 'Cleared '.$n.' directive'.($n === 1 ? '' : 's').' — locations kept.' : 'No directives to clear.');
    }
}
