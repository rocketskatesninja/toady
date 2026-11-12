<?php

namespace Tests\Feature;

use App\Http\Controllers\AiController;
use App\Models\Op;
use App\Models\Presence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:User,2:Op} */
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

    // ---- the streamed tool loop (providers + Overpass faked) ----

    /** An SSE body made of `data:` events. */
    private function sse(array ...$events): string
    {
        return implode('', array_map(fn ($e) => 'data: '.json_encode($e)."\n\n", $events))."data: [DONE]\n\n";
    }

    /** A Responses-API text answer; $finish 'length' ends it as cut off (incomplete: max_output_tokens). */
    private function openAiText(string $text, string $finish = 'stop'): string
    {
        return $this->sse(...array_filter([
            $text !== '' ? ['type' => 'response.output_text.delta', 'output_index' => 0, 'delta' => $text] : null,
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]],
            $finish === 'length'
                ? ['type' => 'response.incomplete', 'response' => ['incomplete_details' => ['reason' => 'max_output_tokens']]]
                : ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]));
    }

    /** A Responses-API turn from a reasoning model: an encrypted reasoning item, then a function call. */
    private function openAiToolCall(string $name, array $args): string
    {
        return $this->sse(
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'enc', 'summary' => []]],
            ['type' => 'response.function_call_arguments.delta', 'output_index' => 1, 'delta' => '{"lat'],
            ['type' => 'response.output_item.done', 'output_index' => 1, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => $name, 'arguments' => json_encode($args)]],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        );
    }

    private function overpassFood(): array
    {
        return ['elements' => [
            ['type' => 'node', 'id' => 2, 'lat' => 31.105, 'lon' => -81.4, 'tags' => ['amenity' => 'restaurant', 'name' => 'Far Diner']],
            ['type' => 'node', 'id' => 1, 'lat' => 31.1005, 'lon' => -81.4, 'tags' => ['amenity' => 'cafe', 'name' => "Bubba's", 'cuisine' => 'coffee_shop']],
        ]];
    }

    private function ask(User $user, Op $op, string $provider, string $model = 'm'): string
    {
        return $this->actingAs($user)->postJson("/ops/{$op->public_id}/ai", ['provider' => $provider, 'key' => 'sk-test', 'model' => $model, 'messages' => [['role' => 'user', 'content' => 'where can we eat?']]])
            ->assertOk()->streamedContent();
    }

    public function test_openai_tool_call_runs_the_tool_then_answers_with_sources(): void
    {
        [, $agent, $op] = $this->scene();
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => Http::sequence()->push($this->openAiToolCall('find_food', ['lat' => 31.1, 'lng' => -81.4]))->push($this->openAiText("Try Bubba's.")),
            'overpass-api.de/*' => Http::response($this->overpassFood()),
        ]);

        $out = $this->ask($agent, $op, 'openai');

        $this->assertStringContainsString('"tool":"OpenStreetMap restaurants"', $out);
        $this->assertStringContainsString('"t":"Try Bubba\'s."', $out);
        $this->assertMatchesRegularExpression('/"sources":\[\{"title":"[^"]*OpenStreetMap contributors"/', $out);
        // the second turn carried the tool result back to the model
        // Responses API, nothing stored at OpenAI, tools in the flat non-strict shape
        Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/responses' && $r['store'] === false
            && $r['tools'][0]['type'] === 'function' && $r['tools'][0]['strict'] === false && isset($r['tools'][0]['parameters']));
        // the second turn sent back the reasoning + call items verbatim, then the tool's output
        Http::assertSent(fn ($r) => str_contains($r->url(), 'openai') && count($r['input']) === 4 && $r['input'][1]['encrypted_content'] === 'enc'
            && $r['input'][2]['call_id'] === 'call_1' && $r['input'][3]['type'] === 'function_call_output'
            && $r['input'][3]['call_id'] === 'call_1' && str_contains($r['input'][3]['output'], "Bubba's"));
    }

    public function test_anthropic_tool_call_round_trips_and_caches_the_manual(): void
    {
        [, $agent, $op] = $this->scene();
        Http::preventStrayRequests();
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->sse(
                    ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'find_food', 'input' => []]],
                    ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"lat":31.1,"lng":-81.4}']],
                    ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
                ))
                ->push($this->sse(
                    ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                    ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Far Diner is 550 m.']],
                    ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
                )),
            'overpass-api.de/*' => Http::response($this->overpassFood()),
        ]);

        $out = $this->ask($agent, $op, 'anthropic');

        $this->assertStringContainsString('Far Diner is 550 m.', $out);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && ($r['system'][0]['cache_control']['type'] ?? null) === 'ephemeral'
            && str_contains($r['system'][1]['text'], 'LIVE OP SNAPSHOT'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && count($r['messages']) === 3
            && $r['messages'][2]['content'][0]['type'] === 'tool_result' && str_contains($r['messages'][2]['content'][0]['content'], 'Far Diner'));
    }

    public function test_the_round_cap_forces_a_final_text_answer(): void
    {
        [, $agent, $op] = $this->scene();
        Http::preventStrayRequests();
        Http::fake([
            // a model that never stops asking for tools — until tools are switched off
            'api.openai.com/*' => fn ($r) => Http::response(($r['tool_choice'] ?? null) === 'none'
                ? $this->openAiText('Here is what I found.')
                : $this->openAiToolCall('elevation', ['lat' => 31.1, 'lng' => -81.4])),
            'api.open-elevation.com/*' => Http::response(['results' => [['elevation' => 3]]]),
        ]);

        $out = $this->ask($agent, $op, 'openai');

        $this->assertStringContainsString('Here is what I found.', $out);
        Http::assertSentCount(AiController::MAX_TOOL_ROUNDS + 1 + 1); // tool turns + final turn + one (cached) elevation lookup
    }

    public function test_an_empty_or_cut_off_reply_still_tells_the_user_something(): void
    {
        [, $agent, $op] = $this->scene();
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::sequence()->push($this->openAiText(''))->push($this->openAiText('Long answer', 'length'))]);

        $this->assertStringContainsString('No answer came back', $this->ask($agent, $op, 'openai'));
        $this->assertStringContainsString('answer cut off', $this->ask($agent, $op, 'openai'));
    }

    public function test_reasoning_models_use_their_default_effort_and_hand_back_encrypted_reasoning(): void
    {
        [, $agent, $op] = $this->scene();
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response($this->openAiText('Try the diner.'))]);

        $this->assertStringContainsString('Try the diner.', $this->ask($agent, $op, 'openai', 'gpt-6.1-sol'));
        // no effort sent (gpt-6.1-sol rejects 'none'; models disagree on the rest) → one request, no retries
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => ! isset($r['reasoning']) && $r['include'] === ['reasoning.encrypted_content']);
    }

    public function test_provider_errors_pass_through_without_the_key(): void
    {
        [, $agent, $op] = $this->scene();
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Incorrect API key provided.']], 401)
            ->push($this->sse(['type' => 'error', 'message' => 'Rate limit reached.']))]);

        $out = $this->ask($agent, $op, 'openai');

        $this->assertStringContainsString('"error":"Incorrect API key provided."', $out);
        $this->assertStringNotContainsString('sk-test', $out);

        // an error event mid-stream reaches the user too
        $this->assertStringContainsString('"error":"Rate limit reached."', $this->ask($agent, $op, 'openai'));
    }

    public function test_the_snapshot_includes_the_askers_own_position_only_while_sharing(): void
    {
        [, $agent, $op] = $this->scene();
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response($this->openAiText('ok'))]);
        $system = fn () => Http::recorded()->last()[0]['instructions'];

        $this->ask($agent, $op, 'openai');
        $this->assertStringContainsString('is not sharing their location', $system());

        Presence::create(['op_id' => $op->id, 'user_id' => $agent->id, 'sharing' => true, 'lat' => 31.123456, 'lng' => -81.4, 'accuracy' => 8, 'last_seen' => now()]);
        $this->ask($agent, $op, 'openai');
        $this->assertStringContainsString('live position (shared', $system());
        $this->assertStringContainsString('±8m): 31.12346,-81.4.', $system());
    }
}
