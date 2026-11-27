<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StepToggleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:\App\Models\User,1:\App\Models\User,2:\App\Models\User,3:Op,4:\App\Models\OpWaypoint} */
    private function scene(): array
    {
        $operative = $this->mkUser(['callsign' => 'Op'.substr(uniqid(), -4), 'faction' => 'ENL']);
        $a = $this->mkUser(['callsign' => 'Ag'.substr(uniqid(), -4), 'faction' => 'ENL']);
        $b = $this->mkUser(['callsign' => 'Bg'.substr(uniqid(), -4), 'faction' => 'RES']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'O', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'S'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $a->id, 'role' => 'agent']);
        $op->participants()->create(['user_id' => $b->id, 'role' => 'agent']);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'P', 'lat' => 31.1, 'lng' => -81.4]);

        return [$operative, $a, $b, $op, $wp];
    }

    public function test_only_the_assigned_agent_can_complete_an_assigned_directive(): void
    {
        [, $a, $b, $op, $wp] = $this->scene();
        $step = $op->steps()->create(['phase' => 'run', 'seq' => 1, 'text' => 'Hack', 'action' => 'hack', 'op_waypoint_id' => $wp->id, 'assignee_id' => $a->id]);

        $this->actingAs($b)->putJson("/ops/{$op->public_id}/steps/{$step->id}/toggle", ['done' => true])->assertForbidden();
        $this->assertFalse((bool) $step->fresh()->done);

        $this->actingAs($a)->putJson("/ops/{$op->public_id}/steps/{$step->id}/toggle", ['done' => true])->assertNoContent();
        $this->assertTrue((bool) $step->fresh()->done);
    }

    public function test_anyone_can_complete_an_unassigned_directive(): void
    {
        [, , $b, $op, $wp] = $this->scene();
        $step = $op->steps()->create(['phase' => 'run', 'seq' => 1, 'text' => 'Open', 'action' => 'hack', 'op_waypoint_id' => $wp->id]);

        $this->actingAs($b)->putJson("/ops/{$op->public_id}/steps/{$step->id}/toggle", ['done' => true])->assertNoContent();
        $this->assertTrue((bool) $step->fresh()->done);
    }

    public function test_operative_can_override_and_complete_an_assigned_directive(): void
    {
        [$operative, $a, , $op, $wp] = $this->scene();
        $step = $op->steps()->create(['phase' => 'run', 'seq' => 1, 'text' => 'X', 'action' => 'hack', 'op_waypoint_id' => $wp->id, 'assignee_id' => $a->id]);

        $this->actingAs($operative)->putJson("/ops/{$op->public_id}/steps/{$step->id}/toggle", ['done' => true])->assertNoContent();
        $this->assertTrue((bool) $step->fresh()->done);
    }
}
