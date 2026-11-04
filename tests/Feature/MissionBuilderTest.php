<?php

namespace Tests\Feature;

use App\Models\MasterPortal;
use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissionBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function op(): array
    {
        $op = Op::create(['owner_id' => ($u = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']))->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'T'.uniqid()]);
        $op->participants()->create(['user_id' => $u->id, 'role' => 'operative']);

        return [$u, $op];
    }

    public function test_generic_location_can_be_created_then_have_a_portal_attached(): void
    {
        [$op_user, $op] = $this->op();

        // generic (coordless) location
        $this->actingAs($op_user)->post("/ops/{$op->public_id}/waypoints", ['title' => 'Staging point'])->assertRedirect();
        $wp = $op->waypoints()->first();
        $this->assertSame('Staging point', $wp->title);
        $this->assertSame('spine', $wp->role); // spine is the default portal type
        $this->assertNull($wp->lat);

        // attach a real catalog portal → it gets placed
        $portal = MasterPortal::create(['guid' => 'g1', 'title' => 'St Marks', 'lat' => 31.15, 'lng' => -81.49, 'gate_pin' => '4242']);
        $this->actingAs($op_user)->put("/ops/{$op->public_id}/waypoints/{$wp->id}", ['portal_id' => $portal->id])->assertRedirect();
        $wp->refresh();
        $this->assertSame('St Marks', $wp->title);
        $this->assertEqualsWithDelta(31.15, (float) $wp->lat, 0.0001);
        $this->assertSame('4242', $wp->gate_pin);
    }

    public function test_new_locations_are_added_to_the_top(): void
    {
        [$op_user, $op] = $this->op();

        $this->actingAs($op_user)->post("/ops/{$op->public_id}/waypoints", ['title' => 'First'])->assertRedirect();
        $this->actingAs($op_user)->post("/ops/{$op->public_id}/waypoints", ['title' => 'Second'])->assertRedirect();

        // the most recently added sits at the top (seq 1); the earlier one is pushed down to seq 2
        $this->assertSame('Second', $op->waypoints()->where('seq', 1)->value('title'));
        $this->assertSame('First', $op->waypoints()->where('seq', 2)->value('title'));
    }

    public function test_location_needs_a_portal_coords_or_name(): void
    {
        [$op_user, $op] = $this->op();
        $this->actingAs($op_user)->post("/ops/{$op->public_id}/waypoints", [])->assertSessionHasErrors('title');
    }

    public function test_task_takes_an_action_under_a_location(): void
    {
        [$op_user, $op] = $this->op();
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'waypoint', 'title' => 'Generic', 'lat' => null, 'lng' => null]);

        $this->actingAs($op_user)->post("/ops/{$op->public_id}/steps", ['text' => 'hack for keys', 'action' => 'hack', 'op_waypoint_id' => $wp->id])->assertRedirect();
        $this->assertDatabaseHas('op_steps', ['op_id' => $op->id, 'text' => 'hack for keys', 'action' => 'hack', 'op_waypoint_id' => $wp->id]);

        // unknown action rejected
        $this->actingAs($op_user)->post("/ops/{$op->public_id}/steps", ['text' => 'x', 'action' => 'bogus', 'op_waypoint_id' => $wp->id])->assertSessionHasErrors('action');
    }

    public function test_directive_can_be_objective_only_or_comment_only_but_not_empty(): void
    {
        [$op_user, $op] = $this->op();
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'waypoint', 'title' => 'Loc']);

        // objective with no comment
        $this->actingAs($op_user)->post("/ops/{$op->public_id}/steps", ['action' => 'hack', 'op_waypoint_id' => $wp->id])->assertRedirect();
        $this->assertDatabaseHas('op_steps', ['op_id' => $op->id, 'action' => 'hack', 'text' => null]);

        // a directive must belong to a location
        $this->actingAs($op_user)->post("/ops/{$op->public_id}/steps", ['action' => 'hack'])->assertSessionHasErrors('op_waypoint_id');

        // empty (no action, no text) is rejected
        $this->actingAs($op_user)->post("/ops/{$op->public_id}/steps", ['op_waypoint_id' => $wp->id])->assertSessionHasErrors();
    }

    public function test_coords_drop_inherits_a_catalog_portals_name_and_intel(): void
    {
        [$op_user, $op] = $this->op();
        MasterPortal::create(['guid' => 'g9', 'title' => 'St Marks', 'lat' => 31.1505, 'lng' => -81.4915, 'gate_pin' => '4242']);

        // dropping by coords (as a pasted Intel link does) matches the nearby catalog portal
        $this->actingAs($op_user)->post("/ops/{$op->public_id}/waypoints", ['lat' => 31.150503, 'lng' => -81.491498])->assertRedirect();
        $named = $op->waypoints()->first();
        $this->assertSame('St Marks', $named->title);
        $this->assertSame('4242', $named->gate_pin);

        // coords with no nearby catalog portal stay untitled (no name to infer)
        $this->actingAs($op_user)->post("/ops/{$op->public_id}/waypoints", ['lat' => 40.0, 'lng' => -100.0])->assertRedirect();
        $this->assertTrue($op->waypoints()->whereNull('title')->exists());
    }

    public function test_clearing_a_directive_description_persists(): void
    {
        [$op_user, $op] = $this->op();
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A']);
        $step = $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'hack', 'text' => 'old note', 'op_waypoint_id' => $wp->id]);

        // clearing the description sends text:null — must validate + persist (previously 422'd and reverted)
        $this->actingAs($op_user)->put("/ops/{$op->public_id}/steps/{$step->id}", ['text' => null])->assertRedirect();
        $this->assertNull($step->fresh()->text);
    }
}
