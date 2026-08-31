<?php

namespace Tests\Feature;

use App\Models\Op;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_then_import_round_trips_the_plan_with_intel(): void
    {
        $operative = $this->mkUser(['google_id' => 'o', 'callsign' => 'Lead', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Brunswick Fan', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'T1']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'St Marks', 'lat' => 31.15, 'lng' => -81.49, 'gate_pin' => '4242', 'parking' => 'street']);
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'text' => 'Deploy anchor', 'action' => 'deploy', 'op_waypoint_id' => $wp->id]);

        $resp = $this->actingAs($operative)->getJson("/ops/{$op->public_id}/export")
            ->assertOk()
            ->assertJsonPath('name', 'Brunswick Fan')
            ->assertJsonPath('waypoints.0.gate_pin', '4242')
            ->assertJsonPath('steps.0.waypoint_seq', 1);
        $plan = $resp->json();

        $importer = $this->mkUser(['google_id' => 'i', 'callsign' => 'New', 'faction' => 'ENL']);
        $this->actingAs($importer)->post('/ops/import', ['plan' => json_encode($plan)])->assertRedirect();

        $newOp = Op::where('owner_id', $importer->id)->latest('id')->first();
        $this->assertNotNull($newOp);
        $this->assertDatabaseHas('op_waypoints', ['op_id' => $newOp->id, 'gate_pin' => '4242', 'parking' => 'street']);
        $newWp = $newOp->waypoints()->first();
        $this->assertDatabaseHas('op_steps', ['op_id' => $newOp->id, 'op_waypoint_id' => $newWp->id, 'action' => 'deploy']);
        $this->assertSame('operative', $newOp->participants()->where('user_id', $importer->id)->value('role'));
    }

    public function test_exports_an_iitc_draw_tools_plan(): void
    {
        $operative = $this->mkUser(['google_id' => 'oi', 'callsign' => 'Lead', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Fan', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'Ti']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $a = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'Anchor', 'lat' => 31.15, 'lng' => -81.49]);
        $b = $op->waypoints()->create(['seq' => 2, 'role' => 'spine', 'title' => 'Spine', 'lat' => 31.16, 'lng' => -81.48]);
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'link', 'op_waypoint_id' => $a->id, 'links' => [$b->id]]);

        $items = $this->actingAs($operative)->getJson("/ops/{$op->public_id}/export?format=iitc")->assertOk()->json();

        $types = array_column($items, 'type');
        $this->assertSame(2, count(array_filter($types, fn ($t) => $t === 'marker'))); // a marker per placed portal
        $this->assertContains('polyline', $types); // the planned link
        $line = collect($items)->firstWhere('type', 'polyline');
        $this->assertEqualsWithDelta(31.15, $line['latLngs'][0]['lat'], 0.001); // from the anchor
        $this->assertEqualsWithDelta(31.16, $line['latLngs'][1]['lat'], 0.001); // to the spine
    }

    public function test_export_import_keeps_objective_only_directives_qty_keys_and_unplaced_locations(): void
    {
        $operative = $this->mkUser(['google_id' => 'o3', 'callsign' => 'Lead', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Full Plan', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'T3']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'Anchor', 'lat' => 31.15, 'lng' => -81.49, 'keys_needed' => 3]);
        $staging = $op->waypoints()->create(['seq' => 2, 'role' => 'waypoint', 'title' => 'Staging']); // unplaced — no coords
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'hack', 'op_waypoint_id' => $wp->id]); // objective only, no text
        $op->steps()->create(['phase' => 'run', 'seq' => 2, 'action' => 'farm keys', 'qty' => 5, 'op_waypoint_id' => $wp->id]);
        $op->steps()->create(['phase' => 'run', 'seq' => 3, 'action' => 'note', 'text' => 'meet here', 'op_waypoint_id' => $staging->id]);

        $plan = $this->actingAs($operative)->getJson("/ops/{$op->public_id}/export")->assertOk()->json();

        $importer = $this->mkUser(['google_id' => 'i3', 'callsign' => 'New', 'faction' => 'ENL']);
        $this->actingAs($importer)->post('/ops/import', ['plan' => json_encode($plan)])->assertRedirect();
        $new = Op::where('owner_id', $importer->id)->latest('id')->first();

        // every directive round-trips (3), incl. the objective-only one and the farm-keys count
        $this->assertSame(3, $new->steps()->count());
        $this->assertTrue($new->steps()->where('action', 'hack')->whereNull('text')->exists());
        $this->assertSame(5, (int) $new->steps()->where('action', 'farm keys')->value('qty'));
        // key needs + the unplaced location (with its directive) survive
        $this->assertSame(3, (int) $new->waypoints()->where('title', 'Anchor')->value('keys_needed'));
        $newStaging = $new->waypoints()->where('title', 'Staging')->first();
        $this->assertNotNull($newStaging);
        $this->assertNull($newStaging->lat);
        $this->assertTrue($new->steps()->where('op_waypoint_id', $newStaging->id)->where('text', 'meet here')->exists());
    }

    public function test_export_import_remaps_link_targets_and_restores_assignees_by_callsign(): void
    {
        $operative = $this->mkUser(['google_id' => 'o4', 'callsign' => 'Lead', 'faction' => 'ENL', 'email' => 'l@x.com']);
        $agent = $this->mkUser(['google_id' => 'a4', 'callsign' => 'Vector', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Spokes', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'T4']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $a = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A', 'lat' => 31.1, 'lng' => -81.4]);
        $b = $op->waypoints()->create(['seq' => 2, 'role' => 'target', 'title' => 'B', 'lat' => 31.2, 'lng' => -81.5]);
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'link', 'links' => [$b->id], 'op_waypoint_id' => $a->id]);
        $op->steps()->create(['phase' => 'run', 'seq' => 2, 'action' => 'hack', 'assignee_id' => $agent->id, 'op_waypoint_id' => $a->id]);

        $plan = $this->actingAs($operative)->getJson("/ops/{$op->public_id}/export")->assertOk()->json();

        $importer = $this->mkUser(['google_id' => 'i4', 'callsign' => 'New', 'faction' => 'ENL']);
        $this->actingAs($importer)->post('/ops/import', ['plan' => json_encode($plan)])->assertRedirect();
        $new = Op::where('owner_id', $importer->id)->latest('id')->first();

        // the link target re-maps to the NEW op's "B" waypoint, not the old id
        $newB = $new->waypoints()->where('title', 'B')->first();
        $this->assertSame([$newB->id], $new->steps()->where('action', 'link')->first()->links);
        // the directive is pre-assigned to the matching 'Vector' account by callsign
        $this->assertSame($agent->id, $new->steps()->where('action', 'hack')->first()->assignee_id);
    }

    public function test_agent_export_requires_allow_export(): void
    {
        $operative = $this->mkUser(['google_id' => 'o2', 'callsign' => 'Lead', 'faction' => 'ENL']);
        $agent = $this->mkUser(['google_id' => 'a2', 'callsign' => 'Grunt', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'T2', 'allow_export' => false]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);

        $this->actingAs($agent)->getJson("/ops/{$op->public_id}/export")->assertForbidden();

        $op->update(['allow_export' => true]);
        $this->actingAs($agent)->getJson("/ops/{$op->public_id}/export")->assertOk();
    }
}
