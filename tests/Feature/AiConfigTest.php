<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_and_clear_encrypted_ai_config(): void
    {
        $u = $this->mkUser(['google_id' => 'ai', 'callsign' => 'A', 'faction' => 'ENL']);

        $this->actingAs($u)->putJson('/profile/ai-config', ['provider' => 'anthropic', 'key' => 'sk-ant-secret', 'model' => 'claude-opus-4-8'])->assertOk();

        $u->refresh();
        $this->assertSame(['provider' => 'anthropic', 'key' => 'sk-ant-secret', 'model' => 'claude-opus-4-8'], $u->ai_config);
        // stored ENCRYPTED — the raw column never contains the plaintext key
        $this->assertStringNotContainsString('sk-ant-secret', (string) DB::table('users')->where('id', $u->id)->value('ai_config'));

        $this->actingAs($u)->deleteJson('/profile/ai-config')->assertOk();
        $this->assertNull($u->fresh()->ai_config);
    }

    public function test_ai_config_validation(): void
    {
        $u = $this->mkUser(['google_id' => 'ai2', 'callsign' => 'B', 'faction' => 'ENL']);
        $this->actingAs($u)->putJson('/profile/ai-config', ['provider' => 'gemini', 'key' => 'x'])->assertStatus(422); // bad provider
        $this->actingAs($u)->putJson('/profile/ai-config', ['provider' => 'anthropic'])->assertStatus(422);           // no key
    }

    public function test_ai_config_is_hidden_from_serialization(): void
    {
        $u = $this->mkUser(['google_id' => 'ai3', 'callsign' => 'C', 'faction' => 'ENL']);
        $u->ai_config = ['provider' => 'openai', 'key' => 'sk-secret', 'model' => 'gpt-4o'];
        $u->save();
        $this->assertArrayNotHasKey('ai_config', $u->fresh()->toArray()); // #[Hidden] — never leaks into payloads
    }
}
