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
        $step = $op->steps()->create(['phase' => 'run', 'seq' => 1, 'text' => 'Deploy']);

        return [$operative, $op, $wp, $step];
    }

    public function test_directive_links_to_a_waypoint_in_the_op(): void
    {
        [$operative, $op, $wp, $step] = $this->opWith();

        $this->actingAs($operative)->put("/ops/{$op->public_id}/steps/{$step->id}", ['op_waypoint_id' => $wp->id])->assertRedirect();
        $this->assertDatabaseHas('op_steps', ['id' => $step->id, 'op_waypoint_id' => $wp->id]);

        // unlink
        $this->actingAs($operative)->put("/ops/{$op->public_id}/steps/{$step->id}", ['op_waypoint_id' => null])->assertRedirect();
        $this->assertDatabaseHas('op_steps', ['id' => $step->id, 'op_waypoint_id' => null]);
    }

    public function test_directive_cannot_link_to_another_ops_waypoint(): void
    {
        [$operative, $op, , $step] = $this->opWith();
        [, , $otherWp] = $this->opWith();

        $this->actingAs($operative)->put("/ops/{$op->public_id}/steps/{$step->id}", ['op_waypoint_id' => $otherWp->id])
            ->assertSessionHasErrors('op_waypoint_id');
    }
}
