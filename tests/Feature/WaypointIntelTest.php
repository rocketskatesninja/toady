<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaypointIntelTest extends TestCase
{
    use RefreshDatabase;

    public function test_operative_sets_op_local_intel_but_an_agent_cannot(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead'.substr(uniqid(), -4), 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Vec'.substr(uniqid(), -4), 'faction' => 'ENL']);
        // intel is editable during planning, by the operative only
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'O', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'I'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'P', 'lat' => 31.1, 'lng' => -81.4]);

        $this->actingAs($agent)->put("/ops/{$op->public_id}/waypoints/{$wp->id}/intel", ['gate_pin' => '1234'])->assertForbidden();

        $this->actingAs($operative)->put("/ops/{$op->public_id}/waypoints/{$wp->id}/intel", ['gate_pin' => '1234', 'parking' => 'rear lot'])->assertRedirect();
        $this->assertDatabaseHas('op_waypoints', ['id' => $wp->id, 'gate_pin' => '1234', 'parking' => 'rear lot']);

        // once active, intel is locked (read-only display)
        $op->update(['status' => 'active']);
        $this->actingAs($operative)->put("/ops/{$op->public_id}/waypoints/{$wp->id}/intel", ['gate_pin' => '9999'])->assertStatus(409);
    }

    public function test_op_local_intel_is_purged_when_the_op_closes(): void
    {
        $operative = $this->mkUser(['callsign' => 'Cmd'.substr(uniqid(), -4), 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'O', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'J'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'P', 'lat' => 31.1, 'lng' => -81.4, 'gate_pin' => '9988']);

        $this->actingAs($operative)->delete("/ops/{$op->public_id}")->assertRedirect();
        $this->assertDatabaseMissing('op_waypoints', ['id' => $wp->id]);
    }
}
