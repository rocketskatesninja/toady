<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOpAccess;
use App\Models\Op;
use App\Models\Presence;
use App\Models\User;
use App\Support\OperatorManual;
use App\Support\TravelTools;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * BYOK AI concierge proxy. The user's OWN OpenAI/Anthropic key arrives per-request, is forwarded to the
 * provider, and is NEVER stored or logged. Upstream hosts are hard-pinned (no client-supplied URLs), so this
 * can't be abused as an open relay. Responses stream back as normalized SSE:
 *   {"t": "text"} · {"tool": "label being consulted"} · {"sources": [{title, url}]} · {"error": "…"} · [DONE]
 */
class AiController extends Controller
{
    use AuthorizesOpAccess;

    /** The BYOK providers this proxy speaks to (their hosts are hard-pinned below). */
    public const PROVIDERS = ['openai', 'anthropic'];

    /** Cap the model dropdown to the newest N — users don't need every legacy/snapshot model the provider lists. */
    private const MODEL_LIMIT = 10;

    /** Model turns that may call tools. The turn after the last one must answer in plain text. */
    public const MAX_TOOL_ROUNDS = 5;

    /** One output cap for both providers (the user pays for their own tokens). */
    private const MAX_OUTPUT_TOKENS = 4096;

    private const GENERIC_ERROR = 'Could not reach the provider — double-check your API key and selected model.';

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
        $data = $this->validJson($request, [
            'provider' => ['required', Rule::in(self::PROVIDERS)],
            'key' => ['required', 'string', 'max:400'],
        ]);
        if (! is_array($data)) {
            return $data;
        }

        try {
            if ($data['provider'] === 'openai') {
                $res = $this->checked(Http::timeout(20)->withToken($data['key'])->get('https://api.openai.com/v1/models'));
                // chat-completions models only — gpt*/o-series, minus the non-chat variants (instruct
                // completion, audio/realtime/transcribe/tts, image, embeddings…) — then the newest MODEL_LIMIT
                $ids = collect($res->json('data', []))
                    ->filter(fn ($m) => (str_starts_with($m['id'] ?? '', 'gpt') || preg_match('/^(o1|o3|o4|chatgpt)/', $m['id'] ?? ''))
                        && ! preg_match('/instruct|audio|realtime|transcribe|tts|image|moderation|embedding|whisper|dall-e/', $m['id'] ?? ''))
                    ->sortByDesc('created')->take(self::MODEL_LIMIT)->pluck('id')->values();
            } else {
                $res = $this->checked(Http::timeout(20)->withHeaders(['x-api-key' => $data['key'], 'anthropic-version' => '2023-06-01'])->get('https://api.anthropic.com/v1/models'));
                // Anthropic lists newest-first; sort explicitly on `created_at` and keep the newest MODEL_LIMIT
                $ids = collect($res->json('data', []))->sortByDesc('created_at')->take(self::MODEL_LIMIT)->pluck('id')->values();
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
        $data = $this->validJson($request, [
            'provider' => ['required', Rule::in(self::PROVIDERS)],
            'key' => ['required', 'string', 'max:400'],
            'model' => ['required', 'string', 'max:120'],
            'messages' => ['required', 'array', 'min:1', 'max:100'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:24000'],
        ]);
        if (! is_array($data)) {
            return $data;
        }

        // [static manual (cacheable), per-request snapshot]
        $system = [OperatorManual::systemPrompt(), $this->opSnapshot($op, $request->user())];
        $messages = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $data['messages']);

        return response()->stream(fn () => $this->converse($data['provider'], $data['key'], $data['model'], $system, $messages), 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no', // tell nginx/Apache not to buffer the stream
        ]);
    }

    /**
     * The tool loop: stream a turn; if the model asked for tools, run them and go again. After MAX_TOOL_ROUNDS
     * the model is called with tools switched off, so every request ends in text the user can read.
     */
    private function converse(string $provider, string $key, string $model, array $system, array $messages): void
    {
        $openai = $provider === 'openai';
        $sources = [];
        $said = false;
        try {
            for ($round = 0; ; $round++) {
                $final = $round >= self::MAX_TOOL_ROUNDS;
                $turn = $openai
                    ? $this->turnOpenAi($key, $model, $system, $messages, $final)
                    : $this->turnAnthropic($key, $model, $system, $messages, $final);
                $said = $said || trim($turn['text']) !== '';
                if (! $turn['calls'] || $final) {
                    break;
                }
                $results = $this->runTools($turn['calls'], $sources);
                array_push($messages, ...($openai ? $this->openAiToolMessages($turn, $results) : $this->anthropicToolMessages($turn, $results)));
            }
            $this->closingNote($turn['stop'], $said);
        } catch (\Throwable $e) {
            $this->emit(['error' => $this->upstreamError($e)]);
        }
        if ($sources) {
            $this->emit(['sources' => array_values($sources)]);
        }
        echo "data: [DONE]\n\n";
        $this->flush();
    }

    /**
     * One streamed OpenAI turn via the Responses API → ['text', 'stop', 'calls' => [[id, name, input]], 'items'].
     * (Chat Completions can't combine function tools with reasoning on newer models, e.g. gpt-6.1.) No reasoning
     * effort is sent: models disagree on which values they accept, so each uses its own default.
     * store:false keeps the conversation off OpenAI's servers; reasoning models then hand back their reasoning
     * encrypted, and the output `items` are passed back verbatim on the next tool round.
     */
    private function turnOpenAi(string $key, string $model, array $system, array $messages, bool $final): array
    {
        $items = [];
        $text = '';
        $stop = null;
        $payload = [
            'model' => $model, 'stream' => true, 'store' => false, 'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
            'instructions' => implode("\n\n", $system),
            'input' => $messages,
            'tools' => $this->toolsFor('openai'),
        ];
        if ($final) {
            $payload['tool_choice'] = 'none';
        }
        if (preg_match('/^(gpt-[5-9]|o[1-9])/', $model)) {
            $payload['include'] = ['reasoning.encrypted_content'];
        }
        $this->relay('https://api.openai.com/v1/responses', ['Authorization' => 'Bearer '.$key], $payload, function (array $json) use (&$items, &$text, &$stop) {
            switch ($json['type'] ?? '') {
                case 'response.output_text.delta':
                case 'response.refusal.delta':
                    if (($json['delta'] ?? '') !== '') {
                        $text .= $json['delta'];
                        $this->emit(['t' => $json['delta']]);
                    }
                    break;
                case 'response.output_item.done':
                    $items[$json['output_index'] ?? count($items)] = $json['item'];
                    break;
                case 'response.completed':
                    $stop = 'completed';
                    break;
                case 'response.incomplete':
                    $stop = $json['response']['incomplete_details']['reason'] ?? 'incomplete';
                    break;
                case 'response.failed':
                case 'error':
                    throw new \UnexpectedValueException(mb_substr((string) ($json['response']['error']['message'] ?? $json['message'] ?? 'The provider reported an error.'), 0, 300));
            }
        });
        ksort($items);
        $calls = [];
        foreach ($items as $it) {
            if (($it['type'] ?? '') === 'function_call') {
                $calls[] = ['id' => $it['call_id'], 'name' => $it['name'], 'input' => json_decode($it['arguments'] ?? '', true) ?: []];
            }
        }

        return ['text' => $text, 'stop' => $stop, 'calls' => $calls, 'items' => array_values($items)];
    }

    /** One streamed Anthropic turn → ['text', 'stop', 'calls' => [[id, name, input]]]. */
    private function turnAnthropic(string $key, string $model, array $system, array $messages, bool $final): array
    {
        $blocks = [];
        $stop = null;
        $payload = [
            'model' => $model, 'max_tokens' => self::MAX_OUTPUT_TOKENS, 'stream' => true,
            'tools' => $this->toolsFor('anthropic'),
            // the manual is identical on every request and every tool round — cache it (tools sit before it in the cached prefix)
            'system' => [
                ['type' => 'text', 'text' => $system[0], 'cache_control' => ['type' => 'ephemeral']],
                ['type' => 'text', 'text' => $system[1]],
            ],
            'messages' => $messages,
        ];
        if ($final) {
            $payload['tool_choice'] = ['type' => 'none'];
        }
        $this->relay('https://api.anthropic.com/v1/messages',
            ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'],
            $payload,
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
        ksort($blocks);
        $text = '';
        $calls = [];
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'text') {
                $text .= $b['text'];
            } elseif (($b['type'] ?? '') === 'tool_use') {
                $calls[] = ['id' => $b['id'], 'name' => $b['name'], 'input' => json_decode($b['_json'] ?: '{}', true) ?: []];
            }
        }

        return ['text' => $text, 'stop' => $stop, 'calls' => $stop === 'tool_use' ? $calls : []];
    }

    /** Run one turn's tool calls: show what's being consulted, collect source links (de-duped by URL) for the
     *  UI, and return each result's text keyed by call id. */
    private function runTools(array $calls, array &$sources): array
    {
        $out = [];
        foreach ($calls as $c) {
            $this->emit(['tool' => TravelTools::label($c['name'])]);
            $r = TravelTools::run($c['name'], $c['input']);
            foreach ($r['sources'] as $s) {
                $sources[$s['url']] = $s;
            }
            $out[$c['id']] = $r['text'];
        }

        return $out;
    }

    /** Responses API: the turn's output items go back verbatim (incl. encrypted reasoning), then each tool's output. */
    private function openAiToolMessages(array $turn, array $results): array
    {
        $msgs = $turn['items'];
        foreach ($turn['calls'] as $c) {
            $msgs[] = ['type' => 'function_call_output', 'call_id' => $c['id'], 'output' => $results[$c['id']]];
        }

        return $msgs;
    }

    private function anthropicToolMessages(array $turn, array $results): array
    {
        $assistant = $turn['text'] !== '' ? [['type' => 'text', 'text' => $turn['text']]] : [];
        $user = [];
        foreach ($turn['calls'] as $c) {
            $assistant[] = ['type' => 'tool_use', 'id' => $c['id'], 'name' => $c['name'], 'input' => (object) $c['input']];
            $user[] = ['type' => 'tool_result', 'tool_use_id' => $c['id'], 'content' => $results[$c['id']]];
        }

        return [['role' => 'assistant', 'content' => $assistant], ['role' => 'user', 'content' => $user]];
    }

    /** Never end silently: say when the answer was cut short or declined, or that nothing came back at all. */
    private function closingNote(?string $stop, bool $said): void
    {
        $note = match (true) {
            in_array($stop, ['max_tokens', 'max_output_tokens'], true) => '*(answer cut off — ask me to continue)*',
            in_array($stop, ['refusal', 'content_filter'], true) => '*(the model declined to answer that)*',
            ! $said => 'No answer came back — try rephrasing the question.',
            default => null,
        };
        if ($note) {
            $this->emit(['t' => ($said ? "\n\n" : '').$note]);
        }
    }

    /** Adapt the provider-agnostic tool schemas to each provider's expected shape. */
    private function toolsFor(string $provider): array
    {
        $schemas = TravelTools::schemas();
        if ($provider === 'openai') {
            // Responses API shape (flat). strict is on by default there and would demand every param be required.
            return array_map(fn ($s) => ['type' => 'function'] + $s + ['strict' => false], $schemas);
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
        $res = $this->checked(Http::withHeaders($headers)
            ->withOptions(['stream' => true, 'timeout' => 0, 'read_timeout' => 120, 'connect_timeout' => 15])
            ->post($url, $payload));
        $body = $res->toPsrResponse()->getBody();
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

    /** Throw the provider's own (key-free) error message when it answered with an error status. */
    private function checked(HttpResponse $res): HttpResponse
    {
        if ($res->failed()) {
            $body = json_decode($res->body(), true);
            $msg = $body['error']['message'] ?? (is_string($body['error'] ?? null) ? $body['error'] : null);
            throw new \UnexpectedValueException(is_string($msg) && $msg !== '' ? mb_substr($msg, 0, 300) : self::GENERIC_ERROR);
        }

        return $res;
    }

    /** A safe, key-free error message for the user. Only the provider's own error text (via checked()) is
     *  passed through; anything else (connection errors carry URLs) becomes the generic message. */
    private function upstreamError(\Throwable $e): string
    {
        return $e instanceof \UnexpectedValueException ? $e->getMessage() : self::GENERIC_ERROR;
    }

    /** A concise, hidden-op-redacted snapshot of the current op for the system prompt. */
    private function opSnapshot(Op $op, User $user): string
    {
        $op->load(['waypoints', 'steps.assignee', 'participants.user', 'keyHoldings']);
        $isOp = $op->isOperative($user);
        [$waypoints, $steps] = $op->visiblePlan($isOp); // the op page's redaction, so the AI can't leak a hidden plan

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
        // Only the asker's OWN position, and only while they are already sharing it with the op.
        $me = Presence::live()->where('op_id', $op->id)->where('user_id', $user->id)->first();
        $lines[] = $me && $me->lat !== null
            ? "{$cs}'s live position (shared ".max(0, (int) $me->last_seen->diffInSeconds(now())).'s ago'.($me->accuracy ? ', ±'.round($me->accuracy).'m' : '').'): '
                .round($me->lat, 5).','.round($me->lng, 5).'. Use it for "near me" questions.'
            : "{$cs} is not sharing their location. For \"near me\" questions, ask where they are or use a waypoint.";
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
}
