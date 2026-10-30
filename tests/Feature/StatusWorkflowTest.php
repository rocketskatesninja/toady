<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StatusWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_op_is_a_locked_draft_agents_stand_by_until_active(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Vector', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'T1']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $op->waypoints()->create(['seq' => 1, 'role' => 'spine', 'title' => 'P', 'lat' => 31.1, 'lng' => -81.4]);

        // agent: standing-by screen with no plan data
        $this->actingAs($agent)->get("/ops/{$op->public_id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('waiting', true)->where('waypoints', []));
        // the draft shows on the agent's dashboard too (as "upcoming"), though opening it still stands by
        $this->actingAs($agent)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->has('ops', 1));
        // but the operative sees it
        $this->actingAs($operative)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->has('ops', 1));

        // operative edits while planning; flips active; then the plan is locked
        $this->actingAs($operative)->post("/ops/{$op->public_id}/waypoints", ['title' => 'Q'])->assertRedirect();
        $this->actingAs($operative)->put("/ops/{$op->public_id}", ['status' => 'active'])->assertRedirect();
        $this->actingAs($operative)->post("/ops/{$op->public_id}/waypoints", ['title' => 'R'])->assertStatus(409);

        // once active, the agent gets the real op (2 waypoints: P + Q; R was blocked)
        $this->actingAs($agent)->get("/ops/{$op->public_id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('waiting', false)->has('waypoints', 2));
        // and it now appears on the agent's dashboard
        $this->actingAs($agent)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->has('ops', 1));
    }

    public function test_join_token_is_only_exposed_to_the_operative(): void
    {
        $operative = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Grunt', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'SECRET123']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);

        // operative sees the invite token; the agent must NOT (it's roster-control credential)
        $this->actingAs($operative)->get("/ops/{$op->public_id}")
            ->assertInertia(fn (Assert $p) => $p->where('op.join_token', 'SECRET123'));
        $this->actingAs($agent)->get("/ops/{$op->public_id}")
            ->assertInertia(fn (Assert $p) => $p->where('op.join_token', null));
    }
}
