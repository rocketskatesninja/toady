<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StepBulkTest extends TestCase
{
    use RefreshDatabase;

    private function planningOp(): array
    {
        $operative = $this->mkUser(['callsign' => 'Op'.substr(uniqid(), -4), 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Ag'.substr(uniqid(), -4), 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'O', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'B'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        foreach (range(1, 3) as $i) {
            $op->waypoints()->create(['seq' => $i, 'role' => 'spine', 'title' => "P$i", 'lat' => 31.0 + $i * 0.001, 'lng' => -81.4]);
        }

        return [$operative, $agent, $op];
    }

    public function test_bulk_adds_an_action_to_every_portal_and_is_idempotent(): void
    {
        [$operative, $agent, $op] = $this->planningOp();

        $this->actingAs($operative)->post("/ops/{$op->public_id}/steps/bulk", ['action' => 'hack', 'assignee_id' => $agent->id])->assertRedirect();
        $this->assertSame(3, $op->steps()->where('action', 'hack')->where('assignee_id', $agent->id)->count());

        // re-run with the same action+assignee → no doubles
        $this->actingAs($operative)->post("/ops/{$op->public_id}/steps/bulk", ['action' => 'hack', 'assignee_id' => $agent->id])->assertRedirect();
        $this->assertSame(3, $op->steps()->where('action', 'hack')->count());

        // a different assignee (anyone) is a distinct directive → adds another per portal
        $this->actingAs($operative)->post("/ops/{$op->public_id}/steps/bulk", ['action' => 'hack'])->assertRedirect();
        $this->assertSame(6, $op->steps()->where('action', 'hack')->count());
    }

    public function test_bulk_link_targets_a_portal_and_skips_the_target_itself(): void
    {
        [$operative, $agent, $op] = $this->planningOp(); // 3 waypoints, seq 1..3
        $target = $op->waypoints()->where('seq', 1)->first();

        $this->actingAs($operative)->post("/ops/{$op->public_id}/steps/bulk", [
            'action' => 'link', 'links' => [$target->id], 'assignee_id' => $agent->id,
        ])->assertRedirect();

        $links = $op->steps()->where('action', 'link')->get();
        $this->assertSame(2, $links->count()); // the other two portals, not the target
        $this->assertFalse($links->contains('op_waypoint_id', $target->id));
        $this->assertTrue($links->every(fn ($s) => $s->links === [$target->id]));
    }

    public function test_bulk_requires_an_operative(): void
    {
        [, $agent, $op] = $this->planningOp();

        $this->actingAs($agent)->post("/ops/{$op->public_id}/steps/bulk", ['action' => 'hack'])->assertForbidden();
        $this->assertSame(0, $op->steps()->count());
    }
}
