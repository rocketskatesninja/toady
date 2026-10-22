<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\Op;
use App\Models\OpStepTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OpStepTemplateController extends Controller
{
    use AuthorizesOpAccess;

    /** Save a location's current directives as a named, reusable template owned by the operator — it'll be
     *  available on every op they run, not just this one. */
    public function store(Request $request, Op $op): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'op_waypoint_id' => ['required', Rule::exists('op_waypoints', 'id')->where('op_id', $op->id)],
        ]);

        // snapshot just the objective + its generic specifics — templates are reused across ops, so the
        // assignee is dropped (it'd never resolve elsewhere). Link targets are op-specific ids too, but a
        // target that points at an ANCHOR is preserved symbolically ("anchor:1"/"anchor:2") — anchors are a
        // stable, named per-op concept, so it re-resolves to the destination op's anchors on apply. Targets
        // that point at anything else (spines/targets) are still dropped.
        $anchorSym = $this->anchorSymbols($op);
        $steps = $op->steps()->where('op_waypoint_id', $data['op_waypoint_id'])->orderBy('seq')->get()
            ->map(function ($s) use ($anchorSym) {
                $row = ['action' => $s->action, 'text' => $s->text, 'mods' => $s->mods, 'qty' => $s->qty];
                $syms = array_values(array_filter(array_map(fn ($id) => $anchorSym[$id] ?? null, (array) $s->links)));
                if ($syms) {
                    $row['links'] = $syms;
                }

                return $row;
            })
            ->all();
        abort_if(empty($steps), 422, 'This location has no directives to save.');

        $request->user()->stepTemplates()->create(['name' => $data['name'], 'steps' => $steps]);

        return back();
    }

    /** Apply one of your templates' directives onto a location (appends to whatever is there). */
    public function apply(Request $request, Op $op, OpStepTemplate $template): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $this->requirePlanning($op);
        abort_if($template->user_id !== $request->user()->id, 404);
        $data = $request->validate([
            'op_waypoint_id' => ['required', Rule::exists('op_waypoints', 'id')->where('op_id', $op->id)],
        ]);

        $anchorIds = array_flip($this->anchorSymbols($op)); // ['anchor:1' => id, 'anchor:2' => id] for THIS op
        $seq = (int) $op->steps()->max('seq');
        foreach ((array) $template->steps as $s) {
            // resolve any saved anchor symbols back to this op's real anchor ids; if it has no anchors yet the
            // target simply can't resolve, so leave the link empty for the operator to set (or run auto-fan).
            $links = array_values(array_filter(array_map(fn ($sym) => $anchorIds[$sym] ?? null, (array) ($s['links'] ?? []))));
            $op->steps()->create([
                'phase' => 'run',
                'seq' => ++$seq,
                'action' => $s['action'] ?? null,
                'text' => $s['text'] ?? null,
                'mods' => $s['mods'] ?? null,
                'qty' => $s['qty'] ?? null,
                'links' => $links ?: null,
                'op_waypoint_id' => $data['op_waypoint_id'],
            ]);
        }

        return back();
    }

    /**
     * The op's anchors (ordered by seq) as stable template symbols: [waypointId => 'anchor:1', ...].
     * Used both to encode link targets on save and — flipped — to re-resolve them on apply.
     *
     * @return array<int, string>
     */
    private function anchorSymbols(Op $op): array
    {
        $map = [];
        foreach ($op->waypoints()->where('role', 'anchor')->orderBy('seq')->pluck('id') as $i => $id) {
            $map[$id] = 'anchor:'.($i + 1);
        }

        return $map;
    }

    public function destroy(Request $request, Op $op, OpStepTemplate $template): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        abort_if($template->user_id !== $request->user()->id, 404);
        $template->delete();

        return back();
    }
}
