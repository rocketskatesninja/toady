<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Op;
use App\Models\OpTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Tasks widget: operators manage the list; agents check off their own or "anyone" tasks. */
class OpTaskTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:User,2:User,3:Op} operator, agent A, agent B, active op */
    private function scene(): array
    {
        $operator = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $a = $this->mkUser(['callsign' => 'Vector', 'faction' => 'ENL']);
        $b = $this->mkUser(['callsign' => 'Nova', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operator->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'T'.uniqid()]);
        $op->participants()->create(['user_id' => $operator->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $a->id, 'role' => 'agent']);
        $op->participants()->create(['user_id' => $b->id, 'role' => 'agent']);

        return [$operator, $a, $b, $op];
    }

    public function test_an_operator_adds_tasks_even_while_the_op_is_live_defaulting_to_anyone(): void
    {
        [$operator, , , $op] = $this->scene();

        $this->actingAs($operator)->post("/ops/{$op->public_id}/tasks", ['text' => '  Bring spare batteries '])->assertRedirect();

        $this->assertDatabaseHas('op_tasks', ['op_id' => $op->id, 'text' => 'Bring spare batteries', 'assignee_id' => null, 'done' => false]);
    }

    public function test_agents_cannot_add_reassign_or_delete_tasks(): void
    {
        [, $a, , $op] = $this->scene();
        $task = $op->tasks()->create(['text' => 'Scout parking']);

        $this->actingAs($a)->post("/ops/{$op->public_id}/tasks", ['text' => 'Mine'])->assertForbidden();
        $this->actingAs($a)->put("/ops/{$op->public_id}/tasks/{$task->id}", ['assignee_id' => $a->id])->assertForbidden();
        $this->actingAs($a)->delete("/ops/{$op->public_id}/tasks/{$task->id}")->assertForbidden();

        $this->assertSame(1, $op->tasks()->count());
        $this->assertNull($task->fresh()->assignee_id);
    }

    public function test_who_can_check_a_task_off(): void
    {
        [$operator, $a, $b, $op] = $this->scene();
        $anyone = $op->tasks()->create(['text' => 'Anyone']);
        $forA = $op->tasks()->create(['text' => 'For A', 'assignee_id' => $a->id]);
        $forB = $op->tasks()->create(['text' => 'For B', 'assignee_id' => $b->id]);
        $toggle = fn ($user, OpTask $t) => $this->actingAs($user)->put("/ops/{$op->public_id}/tasks/{$t->id}/toggle", ['done' => true]);

        $toggle($a, $anyone)->assertRedirect();  // anyone's task
        $toggle($a, $forA)->assertRedirect();    // their own
        $toggle($a, $forB)->assertForbidden();   // someone else's
        $this->assertTrue($anyone->fresh()->done);
        $this->assertSame($a->id, $forA->fresh()->done_by);
        $this->assertFalse($forB->fresh()->done);

        $toggle($operator, $forB)->assertRedirect(); // operators can check off any task
        $this->assertTrue($forB->fresh()->done);

        $this->actingAs($a)->put("/ops/{$op->public_id}/tasks/{$forA->id}/toggle", ['done' => false])->assertRedirect(); // and uncheck
        $this->assertFalse($forA->fresh()->done);
        $this->assertNull($forA->fresh()->done_by);
    }

    public function test_assigning_alerts_the_agent_but_not_yourself(): void
    {
        [$operator, $a, , $op] = $this->scene();

        $this->actingAs($operator)->post("/ops/{$op->public_id}/tasks", ['text' => 'Grab the gate code', 'assignee_id' => $a->id]);
        $alert = Notification::where('user_id', $a->id)->where('type', 'task')->first();
        $this->assertNotNull($alert);
        $this->assertSame("/ops/{$op->public_id}?view=tasks", $alert->url);

        $task = $op->tasks()->create(['text' => 'Mine']);
        $this->actingAs($operator)->put("/ops/{$op->public_id}/tasks/{$task->id}", ['assignee_id' => $operator->id]);
        $this->assertSame(0, Notification::where('user_id', $operator->id)->where('type', 'task')->count());
        $this->assertSame($operator->id, $task->fresh()->assignee_id);
    }

    public function test_assignees_must_be_on_the_op_and_outsiders_see_nothing(): void
    {
        [$operator, , , $op] = $this->scene();
        $outsider = $this->mkUser(['callsign' => 'Ghost', 'faction' => 'RES']);
        $task = $op->tasks()->create(['text' => 'Hold the line']);

        $this->actingAs($operator)->put("/ops/{$op->public_id}/tasks/{$task->id}", ['assignee_id' => $outsider->id]);
        $this->assertNull($task->fresh()->assignee_id); // rejected by validation (memberOf)

        $this->actingAs($outsider)->put("/ops/{$op->public_id}/tasks/{$task->id}/toggle", ['done' => true])->assertNotFound();
        $this->assertFalse($task->fresh()->done);
    }

    public function test_a_task_from_another_op_is_not_reachable_through_this_one(): void
    {
        [$operator, , , $op] = $this->scene();
        $other = Op::create(['owner_id' => $operator->id, 'name' => 'Other', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'O'.uniqid()]);
        $foreign = $other->tasks()->create(['text' => 'Elsewhere']);

        $this->actingAs($operator)->delete("/ops/{$op->public_id}/tasks/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('op_tasks', ['id' => $foreign->id]);
    }

    public function test_kicking_an_agent_frees_their_open_tasks_and_closing_the_op_purges_them(): void
    {
        [$operator, $a, , $op] = $this->scene();
        $open = $op->tasks()->create(['text' => 'Open', 'assignee_id' => $a->id]);
        $done = $op->tasks()->create(['text' => 'Done', 'assignee_id' => $a->id, 'done' => true, 'done_by' => $a->id]);

        $this->actingAs($operator)->delete("/ops/{$op->public_id}/participants/{$a->id}")->assertRedirect();
        $this->assertNull($open->fresh()->assignee_id);   // back to anyone
        $this->assertSame($a->id, $done->fresh()->assignee_id); // finished work keeps its record

        $op->delete();
        $this->assertSame(0, OpTask::count());
    }

    public function test_the_op_page_carries_the_tasks(): void
    {
        [$operator, $a, , $op] = $this->scene();
        $op->tasks()->create(['text' => 'First', 'assignee_id' => $a->id]);

        $this->actingAs($a)->get("/ops/{$op->public_id}")->assertInertia(fn ($p) => $p
            ->has('tasks', 1)
            ->where('tasks.0.text', 'First')
            ->where('tasks.0.assignee', 'Vector'));
    }
}
