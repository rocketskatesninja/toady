<?php

namespace Tests\Feature;

use App\Models\MasterPortal;
use App\Models\Op;
use App\Support\FieldPlanImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpPlanImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_drawtools_polygon_into_portals_and_links(): void
    {
        $r = FieldPlanImporter::parse([
            ['type' => 'polygon', 'latLngs' => [
                ['lat' => 31.15, 'lng' => -81.49], ['lat' => 31.16, 'lng' => -81.48], ['lat' => 31.14, 'lng' => -81.47],
            ]],
        ]);
        $this->assertCount(3, $r['portals']);
        $this->assertCount(3, $r['links']); // triangle = 3 edges
    }

    public function test_dedups_shared_vertices_across_edges(): void
    {
        $r = FieldPlanImporter::parse([
            ['type' => 'polyline', 'latLngs' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2]]],
            ['type' => 'polyline', 'latLngs' => [['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]]],
        ]);
        $this->assertCount(3, $r['portals']); // shared (2,2) is one portal
        $this->assertCount(2, $r['links']);
    }

    public function test_parses_layerdata_titledata_planner_export(): void
    {
        $r = FieldPlanImporter::parse([
            'layerdata' => [
                ['type' => 'polyline', 'latLngs' => [['lat' => 31.019421, 'lng' => -81.528335], ['lat' => 31.454979, 'lng' => -81.365165]]],
                ['type' => 'polyline', 'latLngs' => [['lat' => 31.454979, 'lng' => -81.365165], ['lat' => 31.139907, 'lng' => -81.491832]]],
            ],
            'titledata' => [
                '31.019421,-81.528335' => ['title' => 'Dover Bluff Club', 'guid' => 'g-dover'],
                '31.454979,-81.365165' => ['title' => 'Sapelo Island', 'guid' => 'g-sapelo'],
                '31.139907,-81.491832' => ['title' => 'Potters Wheel', 'guid' => 'g-potters'],
            ],
        ]);
        $this->assertCount(3, $r['portals']);   // 3 unique vertices
        $this->assertCount(2, $r['links']);      // 2 edges
        $dover = collect($r['portals'])->firstWhere('label', 'Dover Bluff Club');
        $this->assertNotNull($dover);
        $this->assertSame('g-dover', $dover['guid']); // titledata supplies name + guid by coordinate
        $this->assertContains('Sapelo Island', collect($r['portals'])->pluck('label')->all());
    }

    public function test_parses_bookmarks_into_portals_only(): void
    {
        $r = FieldPlanImporter::parse([
            'portals' => ['idOthers' => ['bkmrk' => [
                '1' => ['guid' => 'g1', 'latlng' => '31.15,-81.49', 'label' => 'St Marks'],
            ]]],
        ]);
        $this->assertCount(1, $r['portals']);
        $this->assertCount(0, $r['links']);
        $this->assertSame('g1', $r['portals'][0]['guid']);
        $this->assertSame('St Marks', $r['portals'][0]['label']);
    }

    public function test_operative_imports_a_drawtools_plan(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'IM1']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        MasterPortal::create(['guid' => 'g1', 'title' => 'St Marks', 'lat' => 31.15, 'lng' => -81.49, 'gate_pin' => '4242', 'image' => 'https://lh3.googleusercontent.com/stmarks']);

        $plan = json_encode([['type' => 'polygon', 'latLngs' => [
            ['lat' => 31.15, 'lng' => -81.49], ['lat' => 31.16, 'lng' => -81.48], ['lat' => 31.14, 'lng' => -81.47],
        ]]]);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/import", ['plan' => $plan])->assertRedirect();

        $this->assertSame(3, $op->waypoints()->count());
        $this->assertSame(3, $op->steps()->where('action', 'link')->count());
        $this->assertTrue($op->waypoints()->where('title', 'St Marks')->exists());   // catalog-matched name
        $this->assertSame(4242, (int) $op->waypoints()->where('title', 'St Marks')->value('gate_pin')); // + intel
        $this->assertSame('https://lh3.googleusercontent.com/stmarks', $op->waypoints()->where('title', 'St Marks')->value('image')); // + photo
        $this->assertSame(3, $op->waypoints()->where('keys_needed', '>', 0)->count()); // each portal 1 inbound
    }

    public function test_portals_only_import_adds_waypoints_without_links_or_keys(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'IM3']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);

        $plan = json_encode([['type' => 'polygon', 'latLngs' => [
            ['lat' => 31.15, 'lng' => -81.49], ['lat' => 31.16, 'lng' => -81.48], ['lat' => 31.14, 'lng' => -81.47],
        ]]]);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/import", ['plan' => $plan, 'portals_only' => true])->assertRedirect();

        $this->assertSame(3, $op->waypoints()->count());                                  // portals added
        $this->assertSame(0, $op->steps()->where('action', 'link')->count());             // but no link directives
        $this->assertSame(0, $op->waypoints()->where('keys_needed', '>', 0)->count());     // and no key needs
    }

    public function test_import_skips_portals_already_in_the_op(): void
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'IM4']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);

        $plan = json_encode([['type' => 'polygon', 'latLngs' => [
            ['lat' => 31.15, 'lng' => -81.49], ['lat' => 31.16, 'lng' => -81.48], ['lat' => 31.14, 'lng' => -81.47],
        ]]]);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/import", ['plan' => $plan, 'portals_only' => true])->assertRedirect();
        $this->assertSame(3, $op->waypoints()->count());

        // re-importing the same portals (plus one new) adds only the new one — the 3 existing are skipped
        $plan2 = json_encode([['type' => 'polygon', 'latLngs' => [
            ['lat' => 31.15, 'lng' => -81.49], ['lat' => 31.16, 'lng' => -81.48], ['lat' => 31.20, 'lng' => -81.40],
        ]]]);
        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/import", ['plan' => $plan2, 'portals_only' => true])->assertRedirect();
        $this->assertSame(4, $op->waypoints()->count()); // 3 + 1 new; the two repeats were skipped
    }

    public function test_agent_cannot_import_and_import_locks_when_active(): void
    {
        $operative = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Grunt', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'IM2']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $plan = json_encode([['type' => 'marker', 'latLng' => ['lat' => 31.15, 'lng' => -81.49]]]);

        $this->actingAs($agent)->post("/ops/{$op->public_id}/plan/import", ['plan' => $plan])->assertForbidden();

        $op->update(['status' => 'active']);
        $this->actingAs($operative)->post("/ops/{$op->public_id}/plan/import", ['plan' => $plan])->assertStatus(409);
    }
}
