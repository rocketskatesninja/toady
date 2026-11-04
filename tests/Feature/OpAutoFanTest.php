<?php

namespace Tests\Feature;

use App\Models\Op;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one fan action: POST /ops/{op}/plan/fan with mode = links | keys | both (default both).
 * 'links' lays down the fan link directives (+ reorders into throw order); 'keys' drops one
 * "farm keys" directive per location (qty = keys needed); 'both' does both. Every mode always
 * (re)computes and persists each location's key target from the anchors alone.
 */
class OpAutoFanTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:User,2:Op} operative, agent, planning op with 2 placed anchors + 3 placed spines */
    private function fan(): array
    {
        $operative = $this->mkUser(['google_id' => 'o', 'callsign' => 'Lead', 'faction' => 'ENL']);
        $agent = $this->mkUser(['google_id' => 'a', 'callsign' => 'Grunt', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Fan', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'TKN']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        // spines march south; nearest the anchor midpoint is innermost
        $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A1', 'lat' => 31.214, 'lng' => -81.466]);
        $op->waypoints()->create(['seq' => 2, 'role' => 'anchor', 'title' => 'A2', 'lat' => 31.205, 'lng' => -81.509]);
        $op->waypoints()->create(['seq' => 3, 'role' => 'spine', 'title' => 'S1', 'lat' => 31.193, 'lng' => -81.487]);
        $op->waypoints()->create(['seq' => 4, 'role' => 'spine', 'title' => 'S2', 'lat' => 31.180, 'lng' => -81.489]);
        $op->waypoints()->create(['seq' => 5, 'role' => 'spine', 'title' => 'S3', 'lat' => 31.160, 'lng' => -81.490]);

        return [$operative, $agent, $op];
    }

    public function test_links_mode_generates_the_fan_links_and_key_targets(): void
    {
        [$operative, , $op] = $this->fan();
        $id = $op->waypoints()->orderBy('seq')->pluck('id', 'seq'); // seq => waypoint id

        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'links'])->assertRedirect();

        $links = $op->steps()->where('action', 'link')->orderBy('seq')->get();
        $this->assertCount(9, $links); // one directive per single link (3N for 3 spines)
        $this->assertSame(0, $op->steps()->where('action', 'farm keys')->count()); // links mode adds no farm directives

        [$a1, $a2, $s1, $s2, $s3] = [$id[1], $id[2], $id[3], $id[4], $id[5]];
        $targets = fn ($origin) => $links->where('op_waypoint_id', $origin)->pluck('links')->flatten()->all();
        $this->assertSame([$a2], $targets($a1));            // base A1 -> A2
        $this->assertSame([$a1, $a2], $targets($s1));        // innermost spine → both anchors
        $this->assertSame([$a1, $a2, $s1], $targets($s2));   // + link-back to the previous spine
        $this->assertSame([$a1, $a2, $s2], $targets($s3));

        // keys = inbound links + 1 for recharging (derived from the exact fan)
        $key = fn ($wid) => (int) $op->waypoints()->find($wid)->keys_needed;
        $this->assertSame(4, $key($a1)); // 3 spines link in + 1 recharge
        $this->assertSame(5, $key($a2)); // 3 spines + the base link in + 1 recharge
        $this->assertSame(2, $key($s1)); // 1 link-back in + 1 recharge
        $this->assertSame(2, $key($s2));
        $this->assertSame(1, $key($s3)); // apex: no inbound link, just the 1 recharge key
    }

    public function test_keys_mode_drops_a_farm_directive_per_location_and_no_links(): void
    {
        [$operative, , $op] = $this->fan();

        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'keys'])->assertRedirect();

        $farms = $op->steps()->where('action', 'farm keys')->get();
        $this->assertCount(5, $farms); // one per placed portal in the fan
        $this->assertSame(0, $op->steps()->where('action', 'link')->count()); // keys mode adds no link directives

        // qty mirrors the fan key math (inbound links + 1 recharge): A1=4, A2=5, S1=2, S2=2, S3=1
        $qtyFor = fn (string $title) => (int) $farms->firstWhere('op_waypoint_id', $op->waypoints()->where('title', $title)->value('id'))->qty;
        $this->assertSame(4, $qtyFor('A1'));
        $this->assertSame(5, $qtyFor('A2'));
        $this->assertSame(2, $qtyFor('S1'));
        $this->assertSame(2, $qtyFor('S2'));
        $this->assertSame(1, $qtyFor('S3'));
    }

    public function test_both_is_the_default_and_generates_links_and_farm_directives(): void
    {
        [$operative, , $op] = $this->fan();

        // no mode param → defaults to 'both'
        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan")->assertRedirect();

        $this->assertCount(9, $op->steps()->where('action', 'link')->get());
        $this->assertCount(5, $op->steps()->where('action', 'farm keys')->get());
    }

    public function test_assigns_every_generated_directive_to_a_chosen_agent(): void
    {
        [$operative, $agent, $op] = $this->fan();

        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'both', 'assignee_id' => $agent->id])->assertRedirect();

        $generated = $op->steps()->whereIn('action', ['link', 'farm keys'])->get();
        $this->assertCount(14, $generated); // 9 links + 5 farm directives
        $this->assertTrue($generated->every(fn ($s) => $s->assignee_id === $agent->id));
    }

    public function test_rerun_replaces_only_the_kinds_it_generates_and_preserves_others(): void
    {
        [$operative, , $op] = $this->fan();
        $s1 = $op->waypoints()->where('role', 'spine')->orderBy('seq')->first();
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'hack', 'op_waypoint_id' => $s1->id]);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'both'])->assertRedirect();
        [$links, $farms] = [$op->steps()->where('action', 'link')->count(), $op->steps()->where('action', 'farm keys')->count()];
        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'both'])->assertRedirect();

        $this->assertSame($links, $op->steps()->where('action', 'link')->count());       // re-run replaces, no pile-up
        $this->assertSame($farms, $op->steps()->where('action', 'farm keys')->count());
        $this->assertSame(1, $op->steps()->where('action', 'hack')->count());              // unrelated directive survives

        // a links-only re-run leaves the existing farm directives untouched
        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'links'])->assertRedirect();
        $this->assertSame($farms, $op->steps()->where('action', 'farm keys')->count());
    }

    public function test_invalid_mode_is_rejected(): void
    {
        [$operative, , $op] = $this->fan();
        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'bogus'])
            ->assertRedirect()->assertSessionHasErrors('mode');
        $this->assertSame(0, $op->steps()->count()); // nothing generated on a rejected request
    }

    public function test_guards_block_agents_active_ops_and_fanless_ops(): void
    {
        [$operative, $agent, $op] = $this->fan();

        $this->actingAs($agent)->post("/ops/{$op->public_id}/plan/fan")->assertForbidden(); // 403, operative-only

        $op->update(['status' => 'active']);
        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan")->assertStatus(409); // planning-locked
        $op->update(['status' => 'planning']);

        // 2 anchors but no placed spines → nothing to fan
        $bare = Op::create(['owner_id' => $operative->id, 'name' => 'Bare', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'B']);
        $bare->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $bare->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'lat' => 31.2, 'lng' => -81.5]);
        $bare->waypoints()->create(['seq' => 2, 'role' => 'anchor', 'lat' => 31.21, 'lng' => -81.46]);
        $this->actingAs($operative)->post("/ops/{$bare->public_id}/plan/fan")->assertStatus(422);
    }

    public function test_single_anchor_builds_a_fan_from_one_anchor(): void
    {
        $operative = $this->mkUser(['google_id' => 'o1', 'callsign' => 'Solo', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => '1-Anchor', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'ONE']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        // one anchor with three spines fanned W → S → E around it
        $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A', 'lat' => 31.20, 'lng' => -81.48]);
        $op->waypoints()->create(['seq' => 2, 'role' => 'spine', 'title' => 'W', 'lat' => 31.19, 'lng' => -81.50]);
        $op->waypoints()->create(['seq' => 3, 'role' => 'spine', 'title' => 'S', 'lat' => 31.18, 'lng' => -81.48]);
        $op->waypoints()->create(['seq' => 4, 'role' => 'spine', 'title' => 'E', 'lat' => 31.19, 'lng' => -81.46]);
        $id = $op->waypoints()->orderBy('seq')->pluck('id', 'seq');

        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'links'])->assertRedirect();

        $links = $op->steps()->where('action', 'link')->get();
        $this->assertCount(5, $links); // A→W, A→S, W→S, A→E, S→E

        [$a, $w, $s, $e] = [$id[1], $id[2], $id[3], $id[4]];
        $targets = fn ($origin) => $links->where('op_waypoint_id', $origin)->pluck('links')->flatten()->all();
        $this->assertSame([$w, $s, $e], $targets($a)); // anchor throws out to every spine
        $this->assertSame([$s], $targets($w));          // each spine closes its field to the next
        $this->assertSame([$e], $targets($s));

        $key = fn ($wid) => (int) $op->waypoints()->find($wid)->keys_needed;
        $this->assertSame(1, $key($a)); // anchor is never a target → just the recharge key
        $this->assertSame(2, $key($w)); // 1 inbound (from anchor) + 1 recharge
        $this->assertSame(3, $key($s)); // 2 inbound (anchor + W) + 1 recharge
        $this->assertSame(3, $key($e)); // 2 inbound (anchor + S) + 1 recharge
    }

    public function test_single_anchor_needs_at_least_two_other_portals(): void
    {
        $operative = $this->mkUser(['google_id' => 'o2', 'callsign' => 'Lone', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Thin', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'THIN']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        // 1 anchor + only 1 other placed portal → no field can be built
        $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'lat' => 31.20, 'lng' => -81.48]);
        $op->waypoints()->create(['seq' => 2, 'role' => 'spine', 'lat' => 31.19, 'lng' => -81.48]);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'links'])->assertStatus(422);
    }

    public function test_promotes_every_other_placed_portal_to_a_spine(): void
    {
        [$operative, , $op] = $this->fan();
        // re-tag all the spines as targets — you only tagged the anchors; auto-fan should still fold these in
        $op->waypoints()->where('role', 'spine')->update(['role' => 'target']);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'links'])->assertRedirect();

        // every placed non-anchor is now a spine, and the full fan was built
        $this->assertSame(0, $op->waypoints()->whereNotNull('lat')->whereNotIn('role', ['anchor', 'spine'])->count());
        $this->assertSame(3, $op->waypoints()->where('role', 'spine')->count());
        $this->assertCount(9, $op->steps()->where('action', 'link')->get());
    }

    public function test_links_mode_renumbers_waypoints_into_fielding_order(): void
    {
        $operative = $this->mkUser(['google_id' => 'o', 'callsign' => 'Lead', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Pearl', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'PRL']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);

        // spines stored FARTHEST-first (e.g. an IITC import ordered the other way) — the reverse of fielding order
        $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A1', 'lat' => 31.214, 'lng' => -81.466]);
        $op->waypoints()->create(['seq' => 2, 'role' => 'anchor', 'title' => 'A2', 'lat' => 31.205, 'lng' => -81.509]);
        $op->waypoints()->create(['seq' => 3, 'role' => 'spine', 'title' => 'Far', 'lat' => 31.135, 'lng' => -81.487]);  // farthest from anchors
        $op->waypoints()->create(['seq' => 4, 'role' => 'spine', 'title' => 'Mid', 'lat' => 31.170, 'lng' => -81.489]);
        $op->waypoints()->create(['seq' => 5, 'role' => 'spine', 'title' => 'Near', 'lat' => 31.193, 'lng' => -81.487]); // closest to the anchors
        $op->waypoints()->create(['seq' => 6, 'role' => 'target', 'title' => 'Generic']);                                // unplaced — not part of the fan

        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/fan", ['mode' => 'links'])->assertRedirect();

        // anchors first, then spines innermost -> outermost, then the unplaced waypoint keeps its trailing place
        $this->assertSame(
            ['A1', 'A2', 'Near', 'Mid', 'Far', 'Generic'],
            $op->waypoints()->orderBy('seq')->pluck('title')->all(),
        );
    }
}
