<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\Op;
use App\Models\OpTask;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Tasks widget: a minimal op checklist. Operators add, assign and delete tasks — at any time, live ops
 * included (unlike the plan). Anyone on the op may check off an unassigned task; an assigned one only by its
 * assignee, or an operator.
 */
class OpTaskController extends Controller
{
    use AuthorizesOpAccess;

    public function store(Request $request, Op $op): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $data = $request->validate([
            'text' => ['required', 'string', 'max:200'],
            'assignee_id' => ['nullable', $this->memberOf($op)],
        ]);
        $task = $op->tasks()->create(['text' => trim($data['text']), 'assignee_id' => $data['assignee_id'] ?? null]);
        $this->alertAssignee($op, $task, $request->user());

        return back();
    }

    /** Reassign (null = anyone). Text isn't edited — delete and re-add. */
    public function update(Request $request, Op $op, OpTask $task): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $data = $request->validate(['assignee_id' => ['present', 'nullable', $this->memberOf($op)]]);

        $was = $task->assignee_id;
        $task->update(['assignee_id' => $data['assignee_id']]);
        if ($task->assignee_id !== $was) {
            $this->alertAssignee($op, $task, $request->user());
        }

        return back();
    }

    public function toggle(Request $request, Op $op, OpTask $task): Response|RedirectResponse
    {
        $user = $request->user();
        $this->requireLive($op, $user);
        abort_if($task->assignee_id && $task->assignee_id !== $user->id && ! $op->isOperative($user),
            403, 'Only the assigned agent can check this off.');

        $done = $request->boolean('done', ! $task->done);
        $task->update(['done' => $done, 'done_by' => $done ? $user->id : null, 'done_at' => $done ? now() : null]);

        return $request->wantsJson() ? response()->noContent() : back();
    }

    public function destroy(Request $request, Op $op, OpTask $task): RedirectResponse
    {
        $this->requireOperative($op, $request->user());
        $task->delete();

        return back();
    }

    /** "Assigned to you" for a freshly assigned agent (the same alert plan directives send) — not to yourself. */
    private function alertAssignee(Op $op, OpTask $task, User $actor): void
    {
        if ($task->assignee_id && $task->assignee_id !== $actor->id && ($agent = User::find($task->assignee_id))) {
            Notifier::send($agent, 'task', 'Assigned to you · '.$op->name, 'Task: '.$task->text,
                '/ops/'.$op->public_id.'?view=tasks', $op->id, tag: 'task-'.$op->id);
        }
    }
}
