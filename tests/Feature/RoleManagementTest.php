<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function op($owner): Op
    {
        $op = Op::create(['owner_id' => $owner->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'RM'.uniqid()]);
        $op->participants()->create(['user_id' => $owner->id, 'role' => 'operative']);

        return $op;
    }

    public function test_owner_can_demote_kick_and_ban_other_operators(): void
    {
        $owner = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $a = $this->mkUser(['callsign' => 'A', 'faction' => 'ENL']);
        $b = $this->mkUser(['callsign' => 'B', 'faction' => 'ENL']);
        $op = $this->op($owner);
        $op->participants()->create(['user_id' => $a->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $b->id, 'role' => 'operative']);

        $this->actingAs($owner)->post("/ops/{$op->public_id}/participants/{$a->id}/demote")->assertRedirect();
        $this->assertDatabaseHas('op_participants', ['op_id' => $op->id, 'user_id' => $a->id, 'role' => 'agent']);

        $this->actingAs($owner)->delete("/ops/{$op->public_id}/participants/{$b->id}")->assertRedirect();
        $this->assertDatabaseMissing('op_participants', ['op_id' => $op->id, 'user_id' => $b->id]);
    }

    public function test_non_owner_operator_cannot_demote_kick_or_ban_another_operator(): void
    {
        $owner = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $a = $this->mkUser(['callsign' => 'A', 'faction' => 'ENL']);
        $b = $this->mkUser(['callsign' => 'B', 'faction' => 'ENL']);
        $op = $this->op($owner);
        $op->participants()->create(['user_id' => $a->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $b->id, 'role' => 'operative']);

        $this->actingAs($a)->post("/ops/{$op->public_id}/participants/{$b->id}/demote")->assertForbidden();
        $this->actingAs($a)->delete("/ops/{$op->public_id}/participants/{$b->id}")->assertForbidden();
        $this->actingAs($a)->post("/ops/{$op->public_id}/participants/{$b->id}/ban")->assertForbidden();
        $this->assertDatabaseHas('op_participants', ['op_id' => $op->id, 'user_id' => $b->id, 'role' => 'operative']);
    }

    public function test_the_owner_cannot_be_demoted_kicked_or_banned_by_anyone(): void
    {
        $owner = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $a = $this->mkUser(['callsign' => 'A', 'faction' => 'ENL']);
        $op = $this->op($owner);
        $op->participants()->create(['user_id' => $a->id, 'role' => 'operative']);

        $this->actingAs($a)->delete("/ops/{$op->public_id}/participants/{$owner->id}")->assertForbidden();
        $this->actingAs($a)->post("/ops/{$op->public_id}/participants/{$owner->id}/ban")->assertForbidden();
        $this->actingAs($a)->post("/ops/{$op->public_id}/participants/{$owner->id}/demote")->assertForbidden();
        // the owner can't even demote themselves
        $this->actingAs($owner)->post("/ops/{$op->public_id}/participants/{$owner->id}/demote")->assertForbidden();

        $this->assertDatabaseHas('op_participants', ['op_id' => $op->id, 'user_id' => $owner->id, 'role' => 'operative']);
    }

    public function test_any_operator_still_manages_agents(): void
    {
        $owner = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $op2 = $this->mkUser(['callsign' => 'Op2', 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Vec', 'faction' => 'ENL']);
        $op = $this->op($owner);
        $op->participants()->create(['user_id' => $op2->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);

        // a non-owner operator can still kick an agent
        $this->actingAs($op2)->delete("/ops/{$op->public_id}/participants/{$agent->id}")->assertRedirect();
        $this->assertDatabaseMissing('op_participants', ['op_id' => $op->id, 'user_id' => $agent->id]);
    }
}
