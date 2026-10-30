<?php

namespace Tests\Feature;

use App\Models\Op;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpStepTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function planningOp(User $operative, string $token = 'TPL1'): Op
    {
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'planning', 'join_token' => $token]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);

        return $op;
    }

    public function test_operative_saves_a_location_and_applies_it_to_another(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = $this->planningOp($operative);
        $wp1 = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A']);
        $wp2 = $op->waypoints()->create(['seq' => 2, 'role' => 'waypoint', 'title' => 'B']);
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'hack', 'text' => 'hack it', 'op_waypoint_id' => $wp1->id]);
        $op->steps()->create(['phase' => 'run', 'seq' => 2, 'action' => 'mod', 'mods' => 'Shield', 'op_waypoint_id' => $wp1->id]);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/step-templates", ['name' => 'Anchor pack', 'op_waypoint_id' => $wp1->id])->assertRedirect();
        $tpl = $operative->stepTemplates()->first();
        $this->assertNotNull($tpl);
        $this->assertCount(2, $tpl->steps);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/step-templates/{$tpl->id}/apply", ['op_waypoint_id' => $wp2->id])->assertRedirect();
        $this->assertSame(2, $op->steps()->where('op_waypoint_id', $wp2->id)->count());
        $this->assertTrue($op->steps()->where('op_waypoint_id', $wp2->id)->where('action', 'mod')->where('mods', 'Shield')->exists());
    }

    public function test_template_is_reusable_across_ops(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op1 = $this->planningOp($operative, 'OP1');
        $wp1 = $op1->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A']);
        $op1->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'mod', 'mods' => 'Turret', 'op_waypoint_id' => $wp1->id]);
        $op1->steps()->create(['phase' => 'run', 'seq' => 2, 'action' => 'farm keys', 'qty' => 6, 'op_waypoint_id' => $wp1->id]);

        $this->actingAs($operative)->post("/ops/{$op1->public_id}/step-templates", ['name' => 'Standard fort', 'op_waypoint_id' => $wp1->id])->assertRedirect();
        $tpl = $operative->stepTemplates()->first();

        // a DIFFERENT op, owned by the same operator — the template is available and applies cleanly
        $op2 = $this->planningOp($operative, 'OP2');
        $wp2 = $op2->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'Z']);
        $this->actingAs($operative)->post("/ops/{$op2->public_id}/step-templates/{$tpl->id}/apply", ['op_waypoint_id' => $wp2->id])->assertRedirect();

        $applied = $op2->steps()->where('op_waypoint_id', $wp2->id)->get();
        $this->assertCount(2, $applied);
        $this->assertSame('Turret', $applied->firstWhere('action', 'mod')->mods);
        $this->assertSame(6, $applied->firstWhere('action', 'farm keys')->qty);
    }

    public function test_template_stores_only_generic_directive_fields(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = $this->planningOp($operative);
        $agent = $this->mkUser(['callsign' => 'Vec', 'faction' => 'ENL']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $wp1 = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A']);
        $wp2 = $op->waypoints()->create(['seq' => 2, 'role' => 'target', 'title' => 'B']);
        $wp3 = $op->waypoints()->create(['seq' => 3, 'role' => 'waypoint', 'title' => 'C']);
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'hack', 'assignee_id' => $agent->id, 'op_waypoint_id' => $wp1->id]);
        $op->steps()->create(['phase' => 'run', 'seq' => 2, 'action' => 'link', 'links' => [$wp2->id], 'op_waypoint_id' => $wp1->id]);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/step-templates", ['name' => 'Spoke', 'op_waypoint_id' => $wp1->id])->assertRedirect();
        $tpl = $operative->stepTemplates()->first();

        // op-specific ids aren't snapshotted (a template is reused across ops, where they'd be meaningless)
        $this->assertArrayNotHasKey('assignee_id', $tpl->steps[0]);
        $this->assertArrayNotHasKey('links', $tpl->steps[1]);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/step-templates/{$tpl->id}/apply", ['op_waypoint_id' => $wp3->id])->assertRedirect();
        $applied = $op->steps()->where('op_waypoint_id', $wp3->id)->get();
        $this->assertNull($applied->firstWhere('action', 'hack')->assignee_id);
        $this->assertNull($applied->firstWhere('action', 'link')->links);
    }

    public function test_link_to_anchors_is_preserved_symbolically_and_re_resolves_on_apply(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = $this->planningOp($operative, 'TPLA');
        $a1 = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A1']);
        $a2 = $op->waypoints()->create(['seq' => 2, 'role' => 'anchor', 'title' => 'A2']);
        $spine = $op->waypoints()->create(['seq' => 3, 'role' => 'spine', 'title' => 'Spine']);
        // a classic spine directive: throw links to BOTH anchors
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'link', 'links' => [$a1->id, $a2->id], 'op_waypoint_id' => $spine->id]);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/step-templates", ['name' => 'Fan spine', 'op_waypoint_id' => $spine->id])->assertRedirect();
        $tpl = $operative->stepTemplates()->first();
        // stored as portable symbols, not this op's ids
        $this->assertSame(['anchor:1', 'anchor:2'], $tpl->steps[0]['links']);

        // a different op with its OWN two anchors — the symbols must resolve to THEM
        $op2 = $this->planningOp($operative, 'TPLB');
        $z1 = $op2->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'Z1']);
        $z2 = $op2->waypoints()->create(['seq' => 2, 'role' => 'anchor', 'title' => 'Z2']);
        $dest = $op2->waypoints()->create(['seq' => 3, 'role' => 'spine', 'title' => 'Dest']);

        $this->actingAs($operative)->post("/ops/{$op2->public_id}/step-templates/{$tpl->id}/apply", ['op_waypoint_id' => $dest->id])->assertRedirect();
        $applied = $op2->steps()->where('op_waypoint_id', $dest->id)->firstWhere('action', 'link');
        $this->assertSame([$z1->id, $z2->id], $applied->links);
    }

    public function test_anchor_link_target_drops_gracefully_when_destination_has_no_anchors(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = $this->planningOp($operative, 'TPLC');
        $a1 = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A1']);
        $a2 = $op->waypoints()->create(['seq' => 2, 'role' => 'anchor', 'title' => 'A2']);
        $spine = $op->waypoints()->create(['seq' => 3, 'role' => 'spine', 'title' => 'Spine']);
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'link', 'links' => [$a1->id, $a2->id], 'op_waypoint_id' => $spine->id]);
        $this->actingAs($operative)->post("/ops/{$op->public_id}/step-templates", ['name' => 'Fan spine', 'op_waypoint_id' => $spine->id])->assertRedirect();
        $tpl = $operative->stepTemplates()->first();

        // destination op has NO anchors tagged yet — the directive still applies, just with an empty target
        $op2 = $this->planningOp($operative, 'TPLD');
        $dest = $op2->waypoints()->create(['seq' => 1, 'role' => 'waypoint', 'title' => 'Dest']);
        $this->actingAs($operative)->post("/ops/{$op2->public_id}/step-templates/{$tpl->id}/apply", ['op_waypoint_id' => $dest->id])->assertRedirect();
        $applied = $op2->steps()->where('op_waypoint_id', $dest->id)->firstWhere('action', 'link');
        $this->assertNotNull($applied);
        $this->assertNull($applied->links);
    }

    public function test_templates_survive_their_origin_op_closing(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = $this->planningOp($operative);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A']);
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'hack', 'op_waypoint_id' => $wp->id]);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/step-templates", ['name' => 'Keep me', 'op_waypoint_id' => $wp->id])->assertRedirect();
        $this->assertSame(1, $operative->stepTemplates()->count());

        $op->delete(); // closing/purging an op must NOT take the operator's templates with it
        $this->assertSame(1, $operative->stepTemplates()->count());
    }

    public function test_cannot_save_a_template_from_a_location_with_no_directives(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = $this->planningOp($operative);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A']);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/step-templates", ['name' => 'Empty', 'op_waypoint_id' => $wp->id])->assertStatus(422);
        $this->assertSame(0, $operative->stepTemplates()->count());
    }

    public function test_agent_cannot_save_templates(): void
    {
        $operative = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Grunt', 'faction' => 'ENL']);
        $op = $this->planningOp($operative);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A']);
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'hack', 'op_waypoint_id' => $wp->id]);

        $this->actingAs($agent)->post("/ops/{$op->public_id}/step-templates", ['name' => 'X', 'op_waypoint_id' => $wp->id])->assertForbidden();
    }

    public function test_cannot_apply_or_delete_another_operators_template(): void
    {
        $alice = $this->mkUser(['callsign' => 'Alice', 'faction' => 'ENL']);
        $opA = $this->planningOp($alice, 'OPA');
        $wpA = $opA->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A']);
        $opA->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'hack', 'op_waypoint_id' => $wpA->id]);
        $this->actingAs($alice)->post("/ops/{$opA->public_id}/step-templates", ['name' => 'Mine', 'op_waypoint_id' => $wpA->id])->assertRedirect();
        $tpl = $alice->stepTemplates()->first();

        $bob = $this->mkUser(['callsign' => 'Bob', 'faction' => 'RES']);
        $opB = $this->planningOp($bob, 'OPB');
        $wpB = $opB->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'B']);

        $this->actingAs($bob)->post("/ops/{$opB->public_id}/step-templates/{$tpl->id}/apply", ['op_waypoint_id' => $wpB->id])->assertNotFound();
        $this->actingAs($bob)->delete("/ops/{$opB->public_id}/step-templates/{$tpl->id}")->assertNotFound();
        $this->assertSame(1, $alice->stepTemplates()->count()); // still there
    }
}
