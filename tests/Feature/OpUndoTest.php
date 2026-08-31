<?php

namespace Tests\Feature;

use App\Models\Op;
use App\Models\OpUndoSnapshot;
use App\Models\User;
use App\Support\OpPlanSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpUndoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:Op} an operative + a planning op with 2 anchors + 3 placed waypoints (fan geometry) */
    private function fan(): array
    {
        $op = $this->planningOp($operative);
        $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A1', 'lat' => 31.214, 'lng' => -81.466]);
        $op->waypoints()->create(['seq' => 2, 'role' => 'anchor', 'title' => 'A2', 'lat' => 31.205, 'lng' => -81.509]);
        $op->waypoints()->create(['seq' => 3, 'role' => 'waypoint', 'title' => 'S1', 'lat' => 31.193, 'lng' => -81.487]);
        $op->waypoints()->create(['seq' => 4, 'role' => 'waypoint', 'title' => 'S2', 'lat' => 31.180, 'lng' => -81.489]);
        $op->waypoints()->create(['seq' => 5, 'role' => 'waypoint', 'title' => 'S3', 'lat' => 31.160, 'lng' => -81.490]);

        return [$operative, $op];
    }

    /** A bare planning op owned by a fresh operative (bound to the by-ref arg). */
    private function planningOp(?User &$operative, string $seed = 'o'): Op
    {
        $operative = $this->mkUser(['google_id' => $seed, 'callsign' => 'Lead'.$seed, 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op '.$seed, 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'TK'.$seed]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);

        return $op;
    }

    public function test_auto_farm_then_undo_restores_the_pre_farm_plan(): void
    {
        [$operative, $op] = $this->fan();

        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'keys'])->assertRedirect();
        $this->assertGreaterThan(0, $op->steps()->where('action', 'farm keys')->count());
        $this->assertGreaterThan(0, $op->waypoints()->where('keys_needed', '>', 0)->count());
        $this->assertSame(1, $op->undoSnapshots()->count());

        $this->actingAs($operative)->post("/ops/{$op->public_id}/undo")->assertRedirect();

        $this->assertSame(0, $op->steps()->where('action', 'farm keys')->count(), 'farm directives rolled back');
        $this->assertSame(0, $op->waypoints()->where('keys_needed', '>', 0)->count(), 'key targets rolled back');
        $this->assertSame(0, $op->undoSnapshots()->count(), 'snapshot popped');
    }

    public function test_undo_restores_a_removed_waypoint_with_its_steps_and_key_holdings(): void
    {
        [$operative, $op] = $this->fan();
        $a = $op->waypoints()->where('title', 'A1')->first();
        $b = $op->waypoints()->where('title', 'S1')->first();
        $step = $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'link', 'op_waypoint_id' => $b->id, 'links' => [$a->id]]);
        $holding = $op->keyHoldings()->create(['op_waypoint_id' => $b->id, 'user_id' => $operative->id, 'qty' => 2]);

        // remove the waypoint (its step + holding go with it) — then undo
        $this->actingAs($operative)->delete("/ops/{$op->public_id}/waypoints/{$b->id}")->assertRedirect();
        $this->assertNull($op->waypoints()->find($b->id));

        $this->actingAs($operative)->post("/ops/{$op->public_id}/undo")->assertRedirect();

        $restored = $op->waypoints()->find($b->id);
        $this->assertNotNull($restored, 'waypoint restored with its ORIGINAL id');
        $this->assertSame('S1', $restored->title);
        $restoredStep = $op->steps()->find($step->id);
        $this->assertNotNull($restoredStep, 'its directive is back');
        $this->assertSame([$a->id], $restoredStep->links, 'the link target id still resolves');
        $this->assertNotNull($op->keyHoldings()->find($holding->id), 'its key holding is back');
    }

    public function test_stack_caps_at_ten_and_pops_in_order(): void
    {
        $op = $this->planningOp($operative, 'cap');

        // 11 distinct edits (each adds a waypoint) → 11 snapshots pushed, pruned to the newest 10
        for ($i = 1; $i <= 11; $i++) {
            $this->actingAs($operative)->post("/ops/{$op->public_id}/waypoints", ['title' => "P{$i}"])->assertRedirect();
        }
        $this->assertSame(11, $op->waypoints()->count());
        $this->assertSame(10, $op->undoSnapshots()->count(), 'stack capped at 10');

        // 10 undos walk the plan back; the 11th has nothing left
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($operative)->post("/ops/{$op->public_id}/undo")->assertRedirect();
        }
        $this->assertSame(1, $op->waypoints()->count(), 'walked back to the oldest retained snapshot (1 wp)');
        $this->assertSame(0, $op->undoSnapshots()->count());
        $this->actingAs($operative)->post("/ops/{$op->public_id}/undo")->assertStatus(422); // nothing to undo
    }

    public function test_the_change_signature_ignores_row_timestamps(): void
    {
        [, $op] = $this->fan();
        $before = OpPlanSnapshot::capture($op);

        // re-touch every waypoint's updated_at without changing any plan content
        $op->waypoints()->update(['updated_at' => now()->addHour()]);
        $after = OpPlanSnapshot::capture($op);

        $this->assertNotEquals($before, $after, 'the raw rows differ (updated_at moved)');
        $this->assertSame(OpPlanSnapshot::signature($before), OpPlanSnapshot::signature($after), 'but the content signature is unchanged');
    }

    public function test_a_no_op_edit_pushes_no_snapshot(): void
    {
        [$operative, $op] = $this->fan();
        $order = $op->waypoints()->orderBy('seq')->pluck('id')->all();

        // reorder to the SAME order → state unchanged → signature dedupe skips the snapshot
        $this->actingAs($operative)->post("/ops/{$op->public_id}/waypoints/reorder", ['order' => $order])->assertRedirect();
        $this->assertSame(0, $op->undoSnapshots()->count(), 'a no-op edit records nothing');

        // a validation failure likewise records nothing (nothing was written → signature unchanged)
        $this->actingAs($operative)->post("/ops/{$op->public_id}/waypoints", ['title' => str_repeat('x', 5000)])
            ->assertRedirect()->assertSessionHasErrors('title');
        $this->assertSame(0, $op->undoSnapshots()->count());
    }

    public function test_undo_is_operative_planning_and_op_scoped(): void
    {
        [$operative, $op] = $this->fan();
        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'keys'])->assertRedirect(); // push one snapshot

        // an agent can't undo
        $agent = $this->mkUser(['google_id' => 'ag', 'callsign' => 'Grunt', 'faction' => 'ENL']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $this->actingAs($agent)->post("/ops/{$op->public_id}/undo")->assertForbidden();

        // an operative of a DIFFERENT op can't undo this one (IDOR)
        $this->planningOp($outsider, 'x');
        $this->actingAs($outsider)->post("/ops/{$op->public_id}/undo")->assertForbidden();

        // undo is locked once the op is active
        $op->update(['status' => 'active']);
        $this->actingAs($operative)->post("/ops/{$op->public_id}/undo")->assertStatus(409);
        $this->assertSame(1, $op->undoSnapshots()->count(), 'nothing was popped by the blocked attempts');
    }

    public function test_closing_the_op_purges_its_undo_snapshots(): void
    {
        [$operative, $op] = $this->fan();
        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'keys'])->assertRedirect();
        $this->assertSame(1, $op->undoSnapshots()->count());

        $this->actingAs($operative)->delete("/ops/{$op->public_id}")->assertRedirect();

        $this->assertSame(0, OpUndoSnapshot::where('op_id', $op->id)->count(), 'snapshots purged with the op');
    }
}
