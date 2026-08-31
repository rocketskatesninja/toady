<?php

namespace Tests\Feature;

use App\Models\Op;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The route proxy returns road-following geometry plus the total distance and an on-foot ETA.
 * ETA is always walking-paced — never a driving estimate — and there's always a straight-line
 * distance floor so the readout works even when every routing service is unreachable.
 */
class RouteTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:Op} operative + a planning op */
    private function scene(): array
    {
        $operative = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Rt', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'RT']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);

        return [$operative, $op];
    }

    // two points ~1.1 km apart in Brunswick, GA
    private array $coords = [[-81.4900, 31.1500], [-81.4800, 31.1520]];

    public function test_ors_foot_route_passes_through_distance_and_duration(): void
    {
        config(['services.ors.key' => 'test-key']);
        Http::fake([
            'api.openrouteservice.org/*' => Http::response(['features' => [[
                'geometry' => ['type' => 'LineString', 'coordinates' => [[-81.49, 31.15], [-81.48, 31.152]]],
                'properties' => ['summary' => ['distance' => 1180.0, 'duration' => 890.0]],
            ]]], 200),
        ]);
        [$operative, $op] = $this->scene();

        $res = $this->actingAs($operative)->postJson("/ops/{$op->public_id}/route", ['coordinates' => $this->coords])
            ->assertOk()
            ->assertJsonPath('mode', 'foot')
            ->assertJsonPath('geometry.type', 'LineString');
        $this->assertEqualsWithDelta(1180.0, $res->json('distance'), 0.5);
        $this->assertEqualsWithDelta(890.0, $res->json('duration'), 0.5); // ORS foot duration, used as-is
    }

    public function test_osrm_fallback_recomputes_an_on_foot_eta_not_the_driving_time(): void
    {
        config(['services.ors.key' => null]); // no ORS key → OSRM fallback
        Http::fake([
            'router.project-osrm.org/*' => Http::response([
                'code' => 'Ok',
                'routes' => [['distance' => 1350.0, 'duration' => 120.0, 'geometry' => ['type' => 'LineString', 'coordinates' => [[-81.49, 31.15], [-81.48, 31.152]]]]],
            ], 200),
        ]);
        [$operative, $op] = $this->scene();

        $res = $this->actingAs($operative)->postJson("/ops/{$op->public_id}/route", ['coordinates' => $this->coords])
            ->assertOk()
            ->assertJsonPath('mode', 'road');
        $this->assertEqualsWithDelta(1350.0, $res->json('distance'), 0.5);
        // the OSRM driving duration (120s) is discarded; ETA = distance / walking pace (~1000s), not 120s
        $this->assertEqualsWithDelta(1350.0 / 1.35, $res->json('duration'), 1.0);
    }

    public function test_straight_line_distance_is_returned_when_every_service_fails(): void
    {
        config(['services.ors.key' => null]);
        Http::fake(['*' => Http::response('down', 500)]);
        [$operative, $op] = $this->scene();

        $res = $this->actingAs($operative)->postJson("/ops/{$op->public_id}/route", ['coordinates' => $this->coords])
            ->assertOk()
            ->assertJsonPath('mode', 'direct')
            ->assertJsonPath('geometry', null); // no geometry → the frontend draws the straight line

        // ~1.1 km great-circle between the two points, and a walking ETA derived from it
        $this->assertEqualsWithDelta(1100.0, $res->json('distance'), 150.0);
        $this->assertEqualsWithDelta($res->json('distance') / 1.35, $res->json('duration'), 1.0);
    }

    public function test_a_non_participant_cannot_route(): void
    {
        [, $op] = $this->scene();
        $outsider = $this->mkUser(['callsign' => 'Nope', 'faction' => 'RES']);

        $this->actingAs($outsider)->postJson("/ops/{$op->public_id}/route", ['coordinates' => $this->coords])
            ->assertNotFound();
    }
}
