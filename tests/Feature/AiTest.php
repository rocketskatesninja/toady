<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:\App\Models\User,1:\App\Models\User,2:Op} */
    private function scene(): array
    {
        $operative = $this->mkUser(['callsign' => 'Lead'.substr(uniqid(), -5), 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Vec'.substr(uniqid(), -5), 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'K'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);

        return [$operative, $agent, $op];
    }

    public function test_outsiders_cannot_reach_the_ai_proxy(): void
    {
        [, , $op] = $this->scene();
        $outsider = $this->mkUser(['callsign' => 'Out', 'faction' => 'RES']);

        $this->actingAs($outsider)->postJson("/ops/{$op->public_id}/ai/models", ['provider' => 'anthropic', 'key' => 'sk-x'])->assertNotFound();
        $this->actingAs($outsider)->postJson("/ops/{$op->public_id}/ai", ['provider' => 'anthropic', 'key' => 'sk-x', 'model' => 'claude', 'messages' => [['role' => 'user', 'content' => 'hi']]])->assertNotFound();
    }

    public function test_models_endpoint_validates_provider_and_key(): void
    {
        [, $agent, $op] = $this->scene();

        $this->actingAs($agent)->postJson("/ops/{$op->public_id}/ai/models", [])->assertStatus(422); // missing both
        $this->actingAs($agent)->postJson("/ops/{$op->public_id}/ai/models", ['provider' => 'gemini', 'key' => 'x'])->assertStatus(422); // unknown provider
    }

    public function test_chat_endpoint_validates_payload_before_any_upstream_call(): void
    {
        [, $agent, $op] = $this->scene();

        $this->actingAs($agent)->postJson("/ops/{$op->public_id}/ai", ['provider' => 'anthropic', 'key' => 'x', 'model' => 'm'])->assertStatus(422); // no messages
        $this->actingAs($agent)->postJson("/ops/{$op->public_id}/ai", ['provider' => 'anthropic', 'key' => 'x', 'model' => 'm', 'messages' => [['role' => 'system', 'content' => 'x']]])->assertStatus(422); // role must be user|assistant
    }
}
