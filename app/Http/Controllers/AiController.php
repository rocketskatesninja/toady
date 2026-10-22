<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\Op;
use App\Models\User;
use App\Support\OperatorManual;
use App\Support\TravelTools;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * BYOK AI concierge proxy. The user's OWN OpenAI/Anthropic key arrives per-request, is forwarded to the
 * provider, and is NEVER stored or logged. Upstream hosts are hard-pinned (no client-supplied URLs), so this
 * can't be abused as an open relay. Responses stream back as normalized SSE (`data: {"t": "..."}` + `[DONE]`).
 */
class AiController extends Controller
{
    use AuthorizesOpAccess;

    /** Cap the model dropdown to the newest N — users don't need every legacy/snapshot model the provider lists. */
    private const MODEL_LIMIT = 10;

    /** List the chat models available for the supplied provider + key (populates the model dropdown). */
    public function models(Request $request, Op $op): JsonResponse
    {
        $this->requireParticipant($op, $request->user());

        return $this->listModels($request);
    }

    /** Same model lookup, not tied to an op — lets a user manage their key from the profile page (auth only). */
    public function userModels(Request $request): JsonResponse
    {
        return $this->listModels($request);
    }

    private function listModels(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'provider' => ['required', Rule::in(['openai', 'anthropic'])],
            'key' => ['required', 'string', 'max:400'],
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }
        $data = $v->validated();

        try {
            $client = new Client(['timeout' => 20]);
            if ($data['provider'] === 'openai') {
                $res = $client->get('https://api.openai.com/v1/models', ['headers' => ['Authorization' => 'Bearer '.$data['key']]]);
                // chat-completions models only — gpt*/o-series, minus the non-chat variants (instruct
                // completion, audio/realtime/transcribe/tts, image, embeddings…) — then the newest MODEL_LIMIT
                $ids = collect(json_decode((string) $res->getBody(), true)['data'] ?? [])
                    ->filter(fn ($m) => (str_starts_with($m['id'] ?? '', 'gpt') || preg_match('/^(o1|o3|o4|chatgpt)/', $m['id'] ?? ''))
                        && ! preg_match('/instruct|audio|realtime|transcribe|tts|image|moderation|embedding|whisper|dall-e/', $m['id'] ?? ''))
                    ->sortByDesc('created')->take(self::MODEL_LIMIT)->pluck('id')->values();
            } else {
                $res = $client->get('https://api.anthropic.com/v1/models', ['headers' => ['x-api-key' => $data['key'], 'anthropic-version' => '2023-06-01']]);
                // Anthropic lists newest-first; sort explicitly on `created_at` and keep the newest MODEL_LIMIT
                $ids = collect(json_decode((string) $res->getBody(), true)['data'] ?? [])
                    ->sortByDesc('created_at')->take(self::MODEL_LIMIT)->pluck('id')->values();
            }

            return response()->json(['models' => $ids]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->upstreamError($e)], 422);
        }
    }

    /** Stream a chat completion. System prompt = the toady manual + this op's live snapshot. */
    public function chat(Request $request, Op $op): SymfonyResponse
    {
        $this->requireParticipant($op, $request->user());
        $v = Validator::make($request->all(), [
            'provider' => ['required', Rule::in(['openai', 'anthropic'])],
            'key' => ['required', 'string', 'max:400'],
            'model' => ['required', 'string', 'max:120'],
            'messages' => ['required', 'array', 'min:1', 'max:100'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:24000'],
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }
        $data = $v->validated();

        $system = OperatorManual::systemPrompt()."\n\n".$this->opSnapshot($op, $request->user());
        $messages = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $data['messages']);

        return response()->stream(function () use ($data, $system, $messages) {
            $tools = $this->toolsFor($data['provider']);
            try {
                $data['provider'] === 'openai'
                    ? $this->turnOpenAi($data['key'], $data['model'], $system, $messages, $tools, 0)
                    : $this->turnAnthropic($data['key'], $data['model'], $system, $messages, $tools, 0);
            } catch (\Throwable $e) {
                $this->emit(['error' => $this->upstreamError($e)]);
            }
            echo "data: [DONE]\n\n";
            $this->flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no', // tell nginx/Apache not to buffer the stream
        ]);
    }

    /** One streaming turn for OpenAI; if the model calls tools, run them and recurse (max 4 deep). */
    private function turnOpenAi(string $key, string $model, string $system, array $messages, array $tools, int $depth): void
    {
        if ($depth > 4) {
            return;
        }
        $calls = [];
        $finish = null;
        $text = '';
        // gpt-5+ reasoning models default to a reasoning_effort that OpenAI rejects alongside function tools
        // on /v1/chat/completions — and we always send tools, so turn it off for them.
        $payload = ['model' => $model, 'stream' => true, 'tools' => $tools, 'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages)];
        if (preg_match('/^gpt-[5-9]/', $model)) {
            $payload['reasoning_effort'] = 'none';
        }
        $this->relay('https://api.openai.com/v1/chat/completions',
            ['Authorization' => 'Bearer '.$key, 'Content-Type' => 'application/json'],
            $payload,
            function (array $json) use (&$calls, &$finish, &$text) {
                $choice = $json['choices'][0] ?? null;
                if (! $choice) {
                    return;
                }
                $d = $choice['delta'] ?? [];
                if (! empty($d['content'])) {
                    $text .= $d['content'];
                    $this->emit(['t' => $d['content']]);
                }
                foreach ($d['tool_calls'] ?? [] as $tc) {
                    $i = $tc['index'] ?? 0;
                    $calls[$i] ??= ['id' => '', 'name' => '', 'args' => ''];
                    $calls[$i]['id'] .= $tc['id'] ?? '';
                    $calls[$i]['name'] .= $tc['function']['name'] ?? '';
                    $calls[$i]['args'] .= $tc['function']['arguments'] ?? '';
                }
                if (! empty($choice['finish_reason'])) {
                    $finish = $choice['finish_reason'];
                }
            });

        if ($finish !== 'tool_calls' || ! $calls) {
            return; // plain answer already streamed
        }
        ksort($calls);
        $messages[] = ['role' => 'assistant', 'content' => $text ?: null,
            'tool_calls' => array_map(fn ($c) => ['id' => $c['id'], 'type' => 'function', 'function' => ['name' => $c['name'], 'arguments' => $c['args']]], array_values($calls))];
        foreach ($calls as $c) {
            $this->emit(['tool' => $c['name']]);
            $messages[] = ['role' => 'tool', 'tool_call_id' => $c['id'], 'content' => TravelTools::run($c['name'], json_decode($c['args'], true) ?: [])];
        }
        $this->turnOpenAi($key, $model, $system, $messages, $tools, $depth + 1);
    }

    /** One streaming turn for Anthropic; if the model calls tools, run them and recurse (max 4 deep). */
    private function turnAnthropic(string $key, string $model, string $system, array $messages, array $tools, int $depth): void
    {
        if ($depth > 4) {
            return;
        }
        $blocks = [];
        $stop = null;
        $this->relay('https://api.anthropic.com/v1/messages',
            ['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'],
            ['model' => $model, 'max_tokens' => 2048, 'stream' => true, 'tools' => $tools, 'system' => $system, 'messages' => $messages],
            function (array $json) use (&$blocks, &$stop) {
                $type = $json['type'] ?? '';
                if ($type === 'content_block_start') {
                    $blocks[$json['index']] = $json['content_block'] + ['text' => '', '_json' => ''];
                } elseif ($type === 'content_block_delta') {
                    $i = $json['index'];
                    $dt = $json['delta']['type'] ?? '';
                    if ($dt === 'text_delta' && ($json['delta']['text'] ?? '') !== '') {
                        $blocks[$i]['text'] .= $json['delta']['text'];
                        $this->emit(['t' => $json['delta']['text']]);
                    } elseif ($dt === 'input_json_delta') {
                        $blocks[$i]['_json'] .= $json['delta']['partial_json'] ?? '';
                    }
                } elseif ($type === 'message_delta') {
                    $stop = $json['delta']['stop_reason'] ?? $stop;
                }
            });

        if ($stop !== 'tool_use') {
            return; // plain answer already streamed
        }
        ksort($blocks);
        $assistant = [];
        $results = [];
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'text' && $b['text'] !== '') {
                $assistant[] = ['type' => 'text', 'text' => $b['text']];
            } elseif (($b['type'] ?? '') === 'tool_use') {
                $input = json_decode($b['_json'] ?: '{}', true) ?: [];
                $assistant[] = ['type' => 'tool_use', 'id' => $b['id'], 'name' => $b['name'], 'input' => $input];
                $this->emit(['tool' => $b['name']]);
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => TravelTools::run($b['name'], $input)];
            }
        }
        $messages[] = ['role' => 'assistant', 'content' => $assistant];
        $messages[] = ['role' => 'user', 'content' => $results];
        $this->turnAnthropic($key, $model, $system, $messages, $tools, $depth + 1);
    }

    /** Adapt the provider-agnostic tool schemas to each provider's expected shape. */
    private function toolsFor(string $provider): array
    {
        $schemas = TravelTools::schemas();
        if ($provider === 'openai') {
            return array_map(fn ($s) => ['type' => 'function', 'function' => $s], $schemas);
        }

        return array_map(fn ($s) => ['name' => $s['name'], 'description' => $s['description'], 'input_schema' => $s['parameters']], $schemas);
    }

    private function emit(array $obj): void
    {
        echo 'data: '.json_encode($obj)."\n\n";
        $this->flush();
    }

    /** POST to a (hard-pinned) provider URL with stream:true and dispatch each `data:` SSE payload to $onEvent. */
    private function relay(string $url, array $headers, array $payload, callable $onEvent): void
    {
        $res = (new Client)->post($url, [
            'headers' => $headers, 'json' => $payload, 'stream' => true,
            'timeout' => 0, 'read_timeout' => 120, 'connect_timeout' => 15,
        ]);
        $body = $res->getBody();
        $buffer = '';
        while (! $body->eof()) {
            if (connection_aborted()) {
                break; // client closed the tab — stop the (billable) upstream stream
            }
            $buffer .= $body->read(2048);
            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $nl));
                $buffer = substr($buffer, $nl + 1);
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }
                $payloadStr = trim(substr($line, 5));
                if ($payloadStr === '' || $payloadStr === '[DONE]') {
                    continue;
                }
                $json = json_decode($payloadStr, true);
                if (is_array($json)) {
                    $onEvent($json);
                }
            }
        }
    }

    /** A concise, hidden-op-redacted snapshot of the current op for the system prompt. */
    private function opSnapshot(Op $op, User $user): string
    {
        $op->load(['waypoints', 'steps.assignee', 'participants.user', 'keyHoldings']);
        $isOp = $op->isOperative($user);
        $waypoints = $op->waypoints->sortBy('seq')->values();
        $steps = $op->steps;

        // mirror show()'s redaction (one shared implementation) so the AI can't leak a hidden plan
        if ($op->type === 'hidden' && ! $isOp
            && ($visible = Op::visibleWaypointIds($waypoints, $steps)) !== null) {
            $waypoints = $waypoints->filter(fn ($w) => $visible->contains($w->id))->values();
            $steps = $steps->filter(fn ($s) => $s->op_waypoint_id === null || $visible->contains($s->op_waypoint_id))->values();
        }

        $cs = $user->callsign ?: 'the current user';
        if ($op->owner_id === $user->id) {
            $who = 'the Operator who created and runs this op';
        } elseif ($isOp) {
            $who = 'an Operator (can build and run this op)';
        } elseif ($op->roleFor($user) === 'agent') {
            $who = 'an Agent (carries out the directives assigned to them)';
        } else {
            $who = 'a viewer (not on the roster)';
        }
        $lines = [
            "Op: {$op->name} (type {$op->type}, status {$op->status}).",
            "You are speaking with {$cs}, {$who}. Address them by that callsign, and answer from their role's point of view.",
        ];
        if ($op->description) {
            $lines[] = 'Brief: '.$op->description;
        }
        if ($op->goals) {
            $lines[] = 'Goals: '.$op->goals;
        }
        if ($op->shared_notes) {
            $lines[] = 'Op notes (shared): '.$op->shared_notes;
        }
        $lines[] = 'Waypoints ('.$waypoints->count().'):';
        foreach ($waypoints as $w) {
            $tasks = $steps->where('op_waypoint_id', $w->id);
            $held = $op->keyHoldings->where('op_waypoint_id', $w->id)->sum('qty');
            $coord = $w->lat !== null ? round($w->lat, 5).','.round($w->lng, 5) : 'unplaced';
            $keys = $w->keys_needed ? " · keys {$held}/{$w->keys_needed}" : '';
            $dir = $tasks->map(fn ($s) => trim(($s->action ?? 'note').($s->done ? ' ✓' : '').($s->assignee ? ' @'.$s->assignee->callsign : '')))->implode('; ');
            $lines[] = "- #{$w->seq} {$w->title} [{$w->role}] ({$coord}){$keys} — {$tasks->where('done', true)->count()}/{$tasks->count()} directives".($dir ? ": {$dir}" : '');
            $intel = array_filter([
                $w->gate_pin ? "gate {$w->gate_pin}" : null, $w->parking ? "parking: {$w->parking}" : null,
                $w->hours ? "hours: {$w->hours}" : null, $w->access_notes ? "access: {$w->access_notes}" : null,
                $w->hazards ? "hazards: {$w->hazards}" : null,
            ]);
            if ($intel) {
                $lines[] = '    intel: '.implode(' · ', $intel);
            }
        }
        $lines[] = 'Roster: '.$op->participants->map(fn ($p) => $p->user->callsign.' ['.$p->role.']')->implode(', ');

        return "=== LIVE OP SNAPSHOT ===\n".implode("\n", $lines);
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    /** Extract a safe, key-free error message from an upstream failure. */
    private function upstreamError(\Throwable $e): string
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            $body = json_decode((string) $e->getResponse()->getBody(), true);
            $msg = $body['error']['message'] ?? (is_string($body['error'] ?? null) ? $body['error'] : null);
            if (is_string($msg) && $msg !== '') {
                return mb_substr($msg, 0, 300);
            }
        }

        return 'Could not reach the provider — double-check your API key and selected model.';
    }
}
