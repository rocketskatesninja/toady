<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SequentialOpTest extends TestCase
{
    use RefreshDatabase;

    /** An op of $type with two waypoints (seq 1, 2), one directive each, an operator + an agent. */
    private function build(string $type): array
    {
        $owner = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Vec', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $owner->id, 'name' => 'Op', 'type' => $type, 'status' => 'active', 'join_token' => 'SQ'.uniqid()]);
        $op->participants()->create(['user_id' => $owner->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $w1 = $op->waypoints()->create(['seq' => 1, 'role' => 'waypoint', 'title' => 'WP1', 'lat' => 31.15, 'lng' => -81.49]);
        $w2 = $op->waypoints()->create(['seq' => 2, 'role' => 'waypoint', 'title' => 'WP2', 'lat' => 31.16, 'lng' => -81.50]);
        $s1 = $op->steps()->create(['op_waypoint_id' => $w1->id, 'text' => 'hack 1', 'action' => 'hack', 'seq' => 1]);
        $s2 = $op->steps()->create(['op_waypoint_id' => $w2->id, 'text' => 'hack 2', 'action' => 'hack', 'seq' => 2]);

        return compact('owner', 'agent', 'op', 'w1', 'w2', 's1', 's2');
    }

    public function test_sequential_op_blocks_a_later_waypoint_until_the_earlier_one_is_cleared(): void
    {
        ['agent' => $agent, 'op' => $op, 's2' => $s2] = $this->build('visible');

        $this->actingAs($agent)->putJson("/ops/{$op->public_id}/steps/{$s2->id}/toggle", ['done' => true])->assertStatus(422);
        $this->assertFalse((bool) $s2->fresh()->done);
    }

    public function test_sequential_op_allows_the_next_waypoint_once_the_previous_is_cleared(): void
    {
        ['agent' => $agent, 'op' => $op, 's1' => $s1, 's2' => $s2] = $this->build('hidden');

        $this->actingAs($agent)->putJson("/ops/{$op->public_id}/steps/{$s1->id}/toggle", ['done' => true])->assertNoContent();
        $this->actingAs($agent)->putJson("/ops/{$op->public_id}/steps/{$s2->id}/toggle", ['done' => true])->assertNoContent();
        $this->assertTrue((bool) $s2->fresh()->done);
    }

    public function test_sequential_op_blocks_a_later_directive_within_the_same_waypoint(): void
    {
        $owner = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Vec', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $owner->id, 'name' => 'Op', 'type' => 'visible', 'status' => 'active', 'join_token' => 'SQW'.uniqid()]);
        $op->participants()->create(['user_id' => $owner->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $w = $op->waypoints()->create(['seq' => 1, 'role' => 'waypoint', 'title' => 'WP', 'lat' => 31.15, 'lng' => -81.49]);
        $a = $op->steps()->create(['op_waypoint_id' => $w->id, 'text' => 'first', 'action' => 'hack', 'seq' => 1]);
        $b = $op->steps()->create(['op_waypoint_id' => $w->id, 'text' => 'second', 'action' => 'deploy', 'seq' => 2]);

        // the 2nd directive is locked until the 1st is done — even though they share a waypoint
        $this->actingAs($agent)->putJson("/ops/{$op->public_id}/steps/{$b->id}/toggle", ['done' => true])->assertStatus(422);
        $this->assertFalse((bool) $b->fresh()->done);

        // do them in order and both go through
        $this->actingAs($agent)->putJson("/ops/{$op->public_id}/steps/{$a->id}/toggle", ['done' => true])->assertNoContent();
        $this->actingAs($agent)->putJson("/ops/{$op->public_id}/steps/{$b->id}/toggle", ['done' => true])->assertNoContent();
        $this->assertTrue((bool) $b->fresh()->done);
    }

    public function test_operator_may_complete_out_of_order(): void
    {
        ['owner' => $owner, 'op' => $op, 's2' => $s2] = $this->build('visible');

        $this->actingAs($owner)->putJson("/ops/{$op->public_id}/steps/{$s2->id}/toggle", ['done' => true])->assertNoContent();
        $this->assertTrue((bool) $s2->fresh()->done);
    }

    public function test_any_order_op_has_no_sequence_gate(): void
    {
        ['agent' => $agent, 'op' => $op, 's2' => $s2] = $this->build('any_order');

        $this->actingAs($agent)->putJson("/ops/{$op->public_id}/steps/{$s2->id}/toggle", ['done' => true])->assertNoContent();
    }

    public function test_hidden_op_redacts_future_waypoints_from_agents_and_reveals_on_progress(): void
    {
        ['agent' => $agent, 'owner' => $owner, 'op' => $op, 's1' => $s1] = $this->build('hidden');

        // agent sees only the current waypoint + a "1 hidden" count
        $this->actingAs($agent)->get("/ops/{$op->public_id}")->assertInertia(fn (Assert $p) => $p
            ->where('op.hidden_waypoints', 1)->has('waypoints', 1)->has('steps', 1)->etc());

        // the operator who built it sees everything
        $this->actingAs($owner)->get("/ops/{$op->public_id}")->assertInertia(fn (Assert $p) => $p
            ->where('op.hidden_waypoints', 0)->has('waypoints', 2)->etc());

        // clearing WP1 reveals WP2 to the agent
        $s1->update(['done' => true]);
        $this->actingAs($agent)->get("/ops/{$op->public_id}")->assertInertia(fn (Assert $p) => $p
            ->where('op.hidden_waypoints', 0)->has('waypoints', 2)->etc());
    }

    public function test_visible_sequential_op_does_not_redact(): void
    {
        ['agent' => $agent, 'op' => $op] = $this->build('visible');

        $this->actingAs($agent)->get("/ops/{$op->public_id}")->assertInertia(fn (Assert $p) => $p
            ->where('op.hidden_waypoints', 0)->has('waypoints', 2)->etc());
    }

    public function test_title_briefing_and_type_are_locked_once_the_op_is_active(): void
    {
        ['owner' => $owner, 'op' => $op] = $this->build('visible'); // an active op

        $this->actingAs($owner)->put("/ops/{$op->public_id}", [
            'name' => 'Renamed', 'description' => 'new', 'type' => 'any_order', 'allow_export' => true,
        ])->assertRedirect();

        $op->refresh();
        $this->assertSame('Op', $op->name);            // title locked
        $this->assertSame('visible', $op->type);       // type locked
        $this->assertTrue((bool) $op->allow_export);   // export (a runtime toggle) still applies
    }
}
