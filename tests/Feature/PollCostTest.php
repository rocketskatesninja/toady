<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The op page re-fetches a handful of props every 3 seconds for everyone on the op — keep that request cheap. */
class PollCostTest extends TestCase
{
    use RefreshDatabase;

    private const POLLED = 'op,participants,banned,waypoints,steps,tasks,keyHoldings,hackTimers,presence';

    public function test_a_live_poll_stays_within_its_query_budget_and_skips_unpolled_work(): void
    {
        $operator = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $operator->forceFill(['ai_config' => ['provider' => 'openai', 'key' => 'sk-x', 'model' => 'm']])->save();
        $op = Op::createFor($operator, ['name' => 'Op', 'type' => 'any_order']);
        $op->update(['status' => 'active']);
        foreach (['Vector', 'Nova', 'Echo'] as $cs) {
            $agent = $this->mkUser(['callsign' => $cs, 'faction' => 'ENL']);
            $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
            $wp = $op->waypoints()->create(['seq' => $agent->id, 'role' => 'spine', 'title' => "P {$cs}", 'lat' => 31.1, 'lng' => -81.4, 'keys_needed' => 2]);
            $op->steps()->create(['seq' => $agent->id, 'action' => 'hack', 'op_waypoint_id' => $wp->id, 'assignee_id' => $agent->id]);
            $op->keyHoldings()->create(['op_waypoint_id' => $wp->id, 'user_id' => $agent->id, 'qty' => 1]);
            $op->presence()->create(['user_id' => $agent->id, 'sharing' => true, 'lat' => 31.1, 'lng' => -81.4, 'last_seen' => now()]);
            $op->tasks()->create(['text' => "Task {$cs}", 'assignee_id' => $agent->id]);
        }
        $version = $this->actingAs($operator)->get("/ops/{$op->public_id}")->viewData('page')['version'];

        $poll = fn ($user) => $this->actingAs($user)->get("/ops/{$op->public_id}", [
            'X-Inertia' => 'true', 'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'Ops/Show', 'X-Inertia-Partial-Data' => self::POLLED,
        ])->assertOk();

        foreach (['operator' => [$operator, 11], 'agent' => [$op->participants()->where('role', 'agent')->first()->user, 10]] as $who => [$user, $budget]) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $props = $poll($user)->json('props');
            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            $this->assertLessThanOrEqual($budget, count($queries), "{$who} poll ran ".count($queries)." queries:\n".implode("\n", $queries));
            $this->assertCount(4, $props['participants']);
            $this->assertSame('Vector', collect($props['keyHoldings'])->firstWhere('qty', 1)['callsign']); // names still resolve
            $this->assertCount(3, $props['presence']);
            $this->assertArrayNotHasKey('aiConfig', $props); // not polled → never built (no decrypt per poll)
            $this->assertArrayNotHasKey('layouts', $props);
        }
    }
}
