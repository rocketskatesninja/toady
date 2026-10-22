<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaypointClearTest extends TestCase
{
    use RefreshDatabase;

    public function test_clear_all_deletes_every_portal_directive_and_key_holding(): void
    {
        $operative = $this->mkUser(['callsign' => 'Op'.substr(uniqid(), -4), 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'O', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'W'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'P', 'lat' => 31.1, 'lng' => -81.4]);
        $op->waypoints()->create(['seq' => 2, 'role' => 'spine', 'title' => 'Q', 'lat' => 31.2, 'lng' => -81.4]);
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'hack', 'op_waypoint_id' => $wp->id]);
        $op->keyHoldings()->create(['op_waypoint_id' => $wp->id, 'user_id' => $operative->id, 'qty' => 3]);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/waypoints/clear")->assertRedirect();

        $this->assertSame(0, $op->waypoints()->count());
        $this->assertSame(0, $op->steps()->count());          // not orphaned (op_waypoint_id is nullOnDelete)
        $this->assertSame(0, $op->keyHoldings()->count());    // cascade-deleted with the portals
    }

    public function test_clear_all_requires_an_operative(): void
    {
        $operative = $this->mkUser(['callsign' => 'Op'.substr(uniqid(), -4), 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Ag'.substr(uniqid(), -4), 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'O', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'W'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'P', 'lat' => 31.1, 'lng' => -81.4]);

        $this->actingAs($agent)->post("/ops/{$op->public_id}/waypoints/clear")->assertForbidden();
        $this->assertSame(1, $op->waypoints()->count());
    }
}
