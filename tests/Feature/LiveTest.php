<?php

namespace Tests\Feature;

use App\Models\Op;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveTest extends TestCase
{
    use RefreshDatabase;

    private function opWithAgent(): array
    {
        $owner = $this->mkUser(['google_id' => 'o', 'callsign' => 'Lead', 'faction' => 'ENL']);
        $agent = $this->mkUser(['google_id' => 'a', 'callsign' => 'Grunt', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $owner->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'T']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);

        return [$op, $agent];
    }

    public function test_participant_posts_and_reads_chat(): void
    {
        [$op, $agent] = $this->opWithAgent();

        $this->actingAs($agent)->postJson("/ops/{$op->public_id}/chat", ['body' => 'on the anchor'])
            ->assertOk()->assertJsonPath('body', 'on the anchor');

        $this->actingAs($agent)->getJson("/ops/{$op->public_id}/chat")->assertOk()->assertJsonCount(1);
    }

    public function test_non_participant_cannot_chat(): void
    {
        [$op] = $this->opWithAgent();
        $outsider = $this->mkUser(['google_id' => 'x', 'callsign' => 'No', 'faction' => 'RES']);
        $this->actingAs($outsider)->postJson("/ops/{$op->public_id}/chat", ['body' => 'hi'])->assertNotFound();
    }

    public function test_presence_update_records_a_fresh_pin(): void
    {
        [$op, $agent] = $this->opWithAgent();

        $this->actingAs($agent)->postJson("/ops/{$op->public_id}/presence", ['sharing' => true, 'lat' => 31.1, 'lng' => -81.4, 'accuracy' => 12])
            ->assertOk();

        $this->assertDatabaseHas('op_presence', ['op_id' => $op->id, 'user_id' => $agent->id, 'sharing' => true]);
    }
}
