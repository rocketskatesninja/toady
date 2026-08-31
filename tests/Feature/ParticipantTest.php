<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantTest extends TestCase
{
    use RefreshDatabase;

    private function op(): array
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'TOK']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);

        return [$operative, $op];
    }

    public function test_operative_adds_an_agent_by_callsign(): void
    {
        [$operative, $op] = $this->op();
        $this->mkUser(['callsign' => 'Vector', 'faction' => 'ENL']);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/participants", ['callsign' => 'Vector'])->assertRedirect();
        $this->assertDatabaseHas('op_participants', ['op_id' => $op->id, 'role' => 'agent']);
    }

    public function test_operative_kicks_an_agent(): void
    {
        [$operative, $op] = $this->op();
        $agent = $this->mkUser(['callsign' => 'Vector', 'faction' => 'ENL']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);

        $this->actingAs($operative)->delete("/ops/{$op->public_id}/participants/{$agent->id}")->assertRedirect();
        $this->assertDatabaseMissing('op_participants', ['op_id' => $op->id, 'user_id' => $agent->id]);
    }

    public function test_kicking_or_banning_frees_the_agents_open_directives(): void
    {
        [$operative, $op] = $this->op();
        $agent = $this->mkUser(['callsign' => 'Vector', 'faction' => 'ENL']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A']);
        $open = $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'hack', 'op_waypoint_id' => $wp->id, 'assignee_id' => $agent->id]);
        $done = $op->steps()->create(['phase' => 'run', 'seq' => 2, 'action' => 'deploy', 'op_waypoint_id' => $wp->id, 'assignee_id' => $agent->id, 'done' => true]);

        $this->actingAs($operative)->delete("/ops/{$op->public_id}/participants/{$agent->id}")->assertRedirect();
        $this->assertNull($open->fresh()->assignee_id);              // open directive → freed to "anyone"
        $this->assertSame($agent->id, $done->fresh()->assignee_id);  // completed directive → kept as history

        // ban a second agent — same release path
        $agent2 = $this->mkUser(['callsign' => 'Nyx', 'faction' => 'ENL']);
        $op->participants()->create(['user_id' => $agent2->id, 'role' => 'agent']);
        $s = $op->steps()->create(['phase' => 'run', 'seq' => 3, 'action' => 'link', 'op_waypoint_id' => $wp->id, 'assignee_id' => $agent2->id]);
        $this->actingAs($operative)->post("/ops/{$op->public_id}/participants/{$agent2->id}/ban")->assertRedirect();
        $this->assertNull($s->fresh()->assignee_id);
    }

    public function test_ban_removes_and_blocks_rejoining(): void
    {
        [$operative, $op] = $this->op();
        $agent = $this->mkUser(['callsign' => 'Vector', 'faction' => 'ENL']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/participants/{$agent->id}/ban")->assertRedirect();
        $this->assertDatabaseHas('op_bans', ['op_id' => $op->id, 'user_id' => $agent->id]);
        $this->assertDatabaseMissing('op_participants', ['op_id' => $op->id, 'user_id' => $agent->id]);

        // the invite link no longer lets them in
        $this->actingAs($agent)->get('/j/TOK')->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('op_participants', ['op_id' => $op->id, 'user_id' => $agent->id]);
    }

    public function test_agent_cannot_kick(): void
    {
        [$operative, $op] = $this->op();
        $a = $this->mkUser(['callsign' => 'A', 'faction' => 'ENL']);
        $b = $this->mkUser(['callsign' => 'B', 'faction' => 'ENL']);
        $op->participants()->create(['user_id' => $a->id, 'role' => 'agent']);
        $op->participants()->create(['user_id' => $b->id, 'role' => 'agent']);

        $this->actingAs($a)->delete("/ops/{$op->public_id}/participants/{$b->id}")->assertForbidden();
    }

    public function test_operative_unbans_an_agent(): void
    {
        [$operative, $op] = $this->op();
        $agent = $this->mkUser(['callsign' => 'Vector', 'faction' => 'ENL']);
        $op->bans()->create(['user_id' => $agent->id, 'banned_by' => $operative->id]);

        $this->actingAs($operative)->delete("/ops/{$op->public_id}/participants/{$agent->id}/ban")->assertRedirect();
        $this->assertDatabaseMissing('op_bans', ['op_id' => $op->id, 'user_id' => $agent->id]);

        // ban lifted → they can be added back by callsign (which is blocked while banned)
        $this->actingAs($operative)->post("/ops/{$op->public_id}/participants", ['callsign' => 'Vector'])->assertRedirect();
        $this->assertDatabaseHas('op_participants', ['op_id' => $op->id, 'user_id' => $agent->id, 'role' => 'agent']);
    }

    public function test_agent_cannot_unban(): void
    {
        [$operative, $op] = $this->op();
        $a = $this->mkUser(['callsign' => 'A', 'faction' => 'ENL']);
        $banned = $this->mkUser(['callsign' => 'B', 'faction' => 'ENL']);
        $op->participants()->create(['user_id' => $a->id, 'role' => 'agent']);
        $op->bans()->create(['user_id' => $banned->id, 'banned_by' => $operative->id]);

        $this->actingAs($a)->delete("/ops/{$op->public_id}/participants/{$banned->id}/ban")->assertForbidden();
        $this->assertDatabaseHas('op_bans', ['op_id' => $op->id, 'user_id' => $banned->id]);
    }
}
