<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoteTest extends TestCase
{
    use RefreshDatabase;

    private function op($owner): Op
    {
        $op = Op::create(['owner_id' => $owner->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'P'.uniqid()]);
        $op->participants()->create(['user_id' => $owner->id, 'role' => 'operative']);

        return $op;
    }

    public function test_operator_promotes_an_agent_to_operator(): void
    {
        $owner = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Vec', 'faction' => 'ENL']);
        $op = $this->op($owner);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);

        $this->actingAs($owner)->post("/ops/{$op->public_id}/participants/{$agent->id}/promote")->assertRedirect();

        $this->assertDatabaseHas('op_participants', ['op_id' => $op->id, 'user_id' => $agent->id, 'role' => 'operative']);
        $this->assertTrue($op->fresh()->isOperative($agent->fresh())); // can now manage/edit
        $this->assertDatabaseHas('notifications', ['user_id' => $agent->id, 'op_id' => $op->id, 'type' => 'join']);
    }

    public function test_an_agent_cannot_promote_anyone(): void
    {
        $owner = $this->mkUser(['callsign' => 'Boss2', 'faction' => 'ENL']);
        $a1 = $this->mkUser(['callsign' => 'A1', 'faction' => 'ENL']);
        $a2 = $this->mkUser(['callsign' => 'A2', 'faction' => 'ENL']);
        $op = $this->op($owner);
        $op->participants()->create(['user_id' => $a1->id, 'role' => 'agent']);
        $op->participants()->create(['user_id' => $a2->id, 'role' => 'agent']);

        $this->actingAs($a1)->post("/ops/{$op->public_id}/participants/{$a2->id}/promote")->assertForbidden();
        $this->assertDatabaseHas('op_participants', ['op_id' => $op->id, 'user_id' => $a2->id, 'role' => 'agent']);
    }
}
