<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StepLinkTest extends TestCase
{
    use RefreshDatabase;

    private function opWith(): array
    {
        $operative = $this->mkUser(['callsign' => 'Op'.substr(uniqid(), -8), 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'T'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'St Marks', 'lat' => 31.15, 'lng' => -81.49]);
        $step = $op->steps()->create(['phase' => 'run', 'seq' => 1, 'text' => 'Deploy', 'op_waypoint_id' => $wp->id]);

        return [$operative, $op, $wp, $step];
    }

    public function test_a_directive_stays_on_its_portal_and_goes_with_it(): void
    {
        [$operative, $op, $wp, $step] = $this->opWith();
        $other = $op->waypoints()->create(['seq' => 2, 'role' => 'spine', 'title' => 'Elsewhere']);

        // an edit can't move it to another portal or cut it loose
        $this->actingAs($operative)->put("/ops/{$op->public_id}/steps/{$step->id}", ['text' => 'Deploy all', 'op_waypoint_id' => $other->id])->assertRedirect();
        $this->assertDatabaseHas('op_steps', ['id' => $step->id, 'text' => 'Deploy all', 'op_waypoint_id' => $wp->id]);

        // removing the portal removes its directives (the database cascades it)
        $this->actingAs($operative)->delete("/ops/{$op->public_id}/waypoints/{$wp->id}")->assertRedirect();
        $this->assertDatabaseMissing('op_steps', ['id' => $step->id]);
    }

    public function test_another_ops_directive_or_portal_is_not_found_under_this_op(): void
    {
        [$operative, $op, $wp, $step] = $this->opWith();
        [, $otherOp, $otherWp, $otherStep] = $this->opWith();
        $otherOp->participants()->create(['user_id' => $operative->id, 'role' => 'operative']); // on both rosters

        $this->actingAs($operative)->put("/ops/{$op->public_id}/steps/{$otherStep->id}", ['text' => 'hijack'])->assertNotFound();
        $this->actingAs($operative)->delete("/ops/{$op->public_id}/steps/{$otherStep->id}")->assertNotFound();
        $this->actingAs($operative)->put("/ops/{$op->public_id}/waypoints/{$otherWp->id}", ['title' => 'hijack'])->assertNotFound();
        $this->actingAs($operative)->delete("/ops/{$op->public_id}/waypoints/{$otherWp->id}")->assertNotFound();
        $this->assertDatabaseHas('op_steps', ['id' => $otherStep->id, 'text' => 'Deploy']);
        $this->assertDatabaseHas('op_waypoints', ['id' => $otherWp->id, 'title' => 'St Marks']);
    }
}
