<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTriggerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:\App\Models\User,1:\App\Models\User,2:Op,3:\App\Models\OpWaypoint} */
    private function scene(): array
    {
        $operative = $this->mkUser(['callsign' => 'Lead'.substr(uniqid(), -5), 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Vec'.substr(uniqid(), -5), 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'K'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'Anchor', 'lat' => 31.1, 'lng' => -81.4, 'keys_needed' => 5]);

        return [$operative, $agent, $op, $wp];
    }

    public function test_keys_notify_operative_once_when_the_target_is_reached(): void
    {
        [$operative, $agent, $op, $wp] = $this->scene();

        // below target → no notification
        $this->actingAs($agent)->put("/ops/{$op->public_id}/keys/{$wp->id}", ['qty' => 3])->assertRedirect();
        $this->assertDatabaseCount('notifications', 0);

        // reaching the target (3 → 5) fires exactly one notification to the operative
        $this->actingAs($agent)->put("/ops/{$op->public_id}/keys/{$wp->id}", ['qty' => 5])->assertRedirect();
        $this->assertDatabaseHas('notifications', ['user_id' => $operative->id, 'op_id' => $op->id, 'type' => 'keys']);
        $this->assertSame(1, \App\Models\Notification::where('type', 'keys')->count());

        // farming MORE keys past the target does not fire again
        $this->actingAs($agent)->put("/ops/{$op->public_id}/keys/{$wp->id}", ['qty' => 9])->assertRedirect();
        $this->assertSame(1, \App\Models\Notification::where('type', 'keys')->count());
    }

    public function test_completing_a_directive_notifies_the_operative_but_not_yourself(): void
    {
        [$operative, $agent, $op, $wp] = $this->scene();
        $step = $op->steps()->create(['phase' => 'run', 'seq' => 1, 'text' => 'Hack the anchor', 'action' => 'hack', 'op_waypoint_id' => $wp->id]);

        // agent finishes it → operative is told
        $this->actingAs($agent)->putJson("/ops/{$op->public_id}/steps/{$step->id}/toggle", ['done' => true])->assertNoContent();
        $this->assertDatabaseHas('notifications', ['user_id' => $operative->id, 'type' => 'done', 'op_id' => $op->id]);

        // operative finishing their own step notifies nobody
        $step2 = $op->steps()->create(['phase' => 'run', 'seq' => 2, 'text' => 'Deploy', 'action' => 'deploy', 'op_waypoint_id' => $wp->id]);
        $this->actingAs($operative)->putJson("/ops/{$op->public_id}/steps/{$step2->id}/toggle", ['done' => true])->assertNoContent();
        $this->assertSame(1, \App\Models\Notification::where('type', 'done')->count());
    }

    public function test_joining_an_op_notifies_the_operative(): void
    {
        [$operative, , $op] = $this->scene();
        $joiner = $this->mkUser(['callsign' => 'NewGuy', 'faction' => 'RES']);

        $this->actingAs($joiner)->get("/j/{$op->join_token}")->assertRedirect();
        $this->assertDatabaseHas('notifications', ['user_id' => $operative->id, 'type' => 'join', 'op_id' => $op->id]);

        // rejoining doesn't re-notify
        $this->actingAs($joiner)->get("/j/{$op->join_token}")->assertRedirect();
        $this->assertSame(1, \App\Models\Notification::where('type', 'join')->count());
    }

    public function test_completing_a_step_passes_the_turn_to_the_next_assigned_agent(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead'.substr(uniqid(), -5), 'faction' => 'ENL']);
        $a = $this->mkUser(['callsign' => 'AaA'.substr(uniqid(), -4), 'faction' => 'ENL']);
        $b = $this->mkUser(['callsign' => 'BbB'.substr(uniqid(), -4), 'faction' => 'RES']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Seq', 'type' => 'visible', 'status' => 'active', 'join_token' => 'T'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $a->id, 'role' => 'agent']);
        $op->participants()->create(['user_id' => $b->id, 'role' => 'agent']);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'P', 'lat' => 31.1, 'lng' => -81.4]);
        $s1 = $op->steps()->create(['phase' => 'run', 'seq' => 1, 'text' => 'First', 'action' => 'hack', 'op_waypoint_id' => $wp->id, 'assignee_id' => $a->id]);
        $s2 = $op->steps()->create(['phase' => 'run', 'seq' => 2, 'text' => 'Second', 'action' => 'link', 'op_waypoint_id' => $wp->id, 'assignee_id' => $b->id]);

        // A finishes step 1 → B is told it's their turn, deep-linked to step 2
        $this->actingAs($a)->putJson("/ops/{$op->public_id}/steps/{$s1->id}/toggle", ['done' => true])->assertNoContent();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $b->id, 'type' => 'turn', 'op_id' => $op->id, 'url' => "/ops/{$op->public_id}?step={$s2->id}",
        ]);
    }
}
