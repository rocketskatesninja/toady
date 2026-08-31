<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpKeyTest extends TestCase
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
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'Anchor', 'lat' => 31.1, 'lng' => -81.4]);

        return [$operative, $agent, $op, $wp];
    }

    public function test_agent_reports_own_holdings_and_zero_clears_the_row(): void
    {
        [, $agent, $op, $wp] = $this->scene();

        $this->actingAs($agent)->put("/ops/{$op->public_id}/keys/{$wp->id}", ['qty' => 4])->assertRedirect();
        $this->assertDatabaseHas('op_key_holdings', ['op_id' => $op->id, 'op_waypoint_id' => $wp->id, 'user_id' => $agent->id, 'qty' => 4]);

        // a second report updates the same row, not a duplicate
        $this->actingAs($agent)->put("/ops/{$op->public_id}/keys/{$wp->id}", ['qty' => 7])->assertRedirect();
        $this->assertSame(1, $op->keyHoldings()->where('user_id', $agent->id)->count());
        $this->assertDatabaseHas('op_key_holdings', ['user_id' => $agent->id, 'qty' => 7]);

        // zero clears it
        $this->actingAs($agent)->put("/ops/{$op->public_id}/keys/{$wp->id}", ['qty' => 0])->assertRedirect();
        $this->assertDatabaseMissing('op_key_holdings', ['op_id' => $op->id, 'user_id' => $agent->id]);
    }

    public function test_only_the_operative_sets_keys_needed(): void
    {
        [$operative, $agent, $op, $wp] = $this->scene(); // scene op is active

        // operative-only…
        $this->actingAs($agent)->put("/ops/{$op->public_id}/keys/{$wp->id}/needed", ['keys_needed' => 6])->assertForbidden();
        // …and planning-only — the plan's key target is locked while the op is active
        $this->actingAs($operative)->put("/ops/{$op->public_id}/keys/{$wp->id}/needed", ['keys_needed' => 6])->assertStatus(409);

        $op->update(['status' => 'planning']);
        $this->actingAs($operative)->put("/ops/{$op->public_id}/keys/{$wp->id}/needed", ['keys_needed' => 6])->assertRedirect();
        $this->assertSame(6, $wp->fresh()->keys_needed);
    }

    public function test_cannot_report_keys_for_another_ops_waypoint(): void
    {
        [, $agent, $op] = $this->scene();
        [, , , $otherWp] = $this->scene();

        $this->actingAs($agent)->put("/ops/{$op->public_id}/keys/{$otherWp->id}", ['qty' => 1])->assertNotFound();
    }

    public function test_non_participant_cannot_report_keys(): void
    {
        [, , $op, $wp] = $this->scene();
        $outsider = $this->mkUser(['callsign' => 'Out', 'faction' => 'RES']);

        $this->actingAs($outsider)->put("/ops/{$op->public_id}/keys/{$wp->id}", ['qty' => 1])->assertNotFound();
    }
}
