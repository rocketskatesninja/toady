<?php

namespace Tests\Feature;

use App\Models\MasterPortal;
use App\Models\Op;
use App\Models\PortalContribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_owner_can_open_or_edit_the_catalog(): void
    {
        $owner = $this->mkUser(['google_id' => 'o', 'callsign' => 'Owner', 'faction' => 'ENL']);
        $owner->forceFill(['is_owner' => true])->save(); // is_owner isn't mass-assignable
        $agent = $this->mkUser(['google_id' => 'a', 'callsign' => 'Agent', 'faction' => 'ENL']);

        $this->actingAs($owner)->get('/catalog')->assertOk();
        $this->actingAs($agent)->get('/catalog')->assertForbidden();

        $this->actingAs($agent)->post('/catalog/portals', ['title' => 'X', 'lat' => 31.1, 'lng' => -81.4])->assertForbidden();
        $this->actingAs($owner)->post('/catalog/portals', ['title' => 'X', 'lat' => 31.1, 'lng' => -81.4])->assertRedirect();
        $this->assertDatabaseHas('master_portals', ['title' => 'X']);
    }

    public function test_any_operative_can_search_the_catalog_read_only(): void
    {
        MasterPortal::create(['guid' => 'p1', 'title' => 'Alpha Portal', 'lat' => 31.1, 'lng' => -81.4]);
        $agent = $this->mkUser(['google_id' => 'a2', 'callsign' => 'Op', 'faction' => 'ENL']);

        $this->actingAs($agent)->getJson('/api/catalog/search?q=Alpha')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.title', 'Alpha Portal');
    }

    public function test_search_ranks_the_nearest_match_first_and_says_how_far(): void
    {
        MasterPortal::create(['guid' => 'far', 'title' => 'First Baptist Church', 'lat' => 32.08, 'lng' => -81.09, 'gate_pin' => '4242']);
        MasterPortal::create(['guid' => 'near', 'title' => 'First Baptist Church', 'lat' => 31.151, 'lng' => -81.491]);
        MasterPortal::create(['guid' => 'none', 'lat' => 31.15, 'lng' => -81.49]); // unnamed: nothing to pick
        $agent = $this->mkUser(['google_id' => 'a3', 'callsign' => 'Op3', 'faction' => 'ENL']);

        $res = $this->actingAs($agent)->getJson('/api/catalog/search?q=baptist+first&lat=31.15&lng=-81.49')->assertOk()->assertJsonCount(2);
        $this->assertLessThan(200, $res->json('0.meters'));       // the one down the road
        $this->assertGreaterThan(100000, $res->json('1.meters')); // the one in the next city
        $this->assertSame(['id', 'title', 'region', 'status', 'meters'], array_keys($res->json('0'))); // no field intel in a search

        // no position given → by name, no distances
        $this->actingAs($agent)->getJson('/api/catalog/search?q=first')->assertOk()->assertJsonPath('0.meters', null);
    }

    public function test_the_owner_imports_an_iitc_export_without_undoing_what_was_decided_here(): void
    {
        $owner = $this->mkUser(['google_id' => 'o2', 'callsign' => 'Owner2', 'faction' => 'ENL']);
        $owner->forceFill(['is_owner' => true])->save();
        $agent = $this->mkUser(['google_id' => 'a4', 'callsign' => 'Op4', 'faction' => 'ENL']);
        $photo = 'https://lh3.googleusercontent.com/abc';
        MasterPortal::create(['guid' => 'g.locked', 'title' => 'My name for it', 'lat' => 31.1, 'lng' => -81.4, 'status' => MasterPortal::OWNER_LOCKED, 'region' => 'St Simons', 'image' => $photo, 'gate_pin' => '1234']);
        MasterPortal::create(['guid' => 'g.guess', 'title' => 'somebodys guess', 'lat' => 31.2, 'lng' => -81.4, 'status' => MasterPortal::UNVERIFIED]);
        $batch = ['source' => 'iitc-test', 'region' => 'Glynn', 'portals' => [
            ['guid' => 'g.locked', 'title' => 'Niantic Name', 'lat' => 31.1001, 'lng' => -81.4001, 'image' => null],
            ['guid' => 'g.guess', 'title' => 'The Real Name', 'lat' => 31.2, 'lng' => -81.4, 'image' => $photo],
            ['guid' => 'g.new', 'title' => null, 'lat' => 31.3, 'lng' => -81.4, 'image' => null],
        ]];

        $this->actingAs($agent)->postJson('/catalog/import', $batch)->assertForbidden();
        $this->actingAs($owner)->postJson('/catalog/import', $batch)->assertOk()->assertExactJson(['added' => 1, 'updated' => 2, 'merged' => 0]);

        // locked: name, region, photo and intel stay; only the position follows the export
        $this->assertDatabaseHas('master_portals', ['guid' => 'g.locked', 'title' => 'My name for it', 'region' => 'St Simons', 'image' => $photo, 'gate_pin' => '1234', 'lat' => 31.1001, 'status' => MasterPortal::OWNER_LOCKED]);
        // a single-source guess takes Niantic's name and is confirmed; an empty region is filled
        $this->assertDatabaseHas('master_portals', ['guid' => 'g.guess', 'title' => 'The Real Name', 'status' => MasterPortal::VERIFIED, 'region' => 'Glynn', 'image' => $photo]);
        $this->assertDatabaseHas('master_portals', ['guid' => 'g.new', 'title' => null, 'status' => MasterPortal::VERIFIED, 'source' => 'iitc-test']);

        // importing the same file again changes nothing, and a later export with no name never blanks a known one
        $batch['portals'][1]['title'] = null;
        $this->actingAs($owner)->postJson('/catalog/import', $batch)->assertOk()->assertExactJson(['added' => 0, 'updated' => 3, 'merged' => 0]);
        $this->assertDatabaseHas('master_portals', ['guid' => 'g.guess', 'title' => 'The Real Name']);
        $this->assertSame(3, MasterPortal::count());

        // a photo link that isn't Niantic's image host is refused
        $batch['portals'][2]['image'] = 'https://evil.example/x.png';
        $this->actingAs($owner)->postJson('/catalog/import', $batch)->assertStatus(422);
    }

    public function test_a_hidden_portal_cannot_be_added_to_a_plan_by_id(): void
    {
        $operative = $this->mkUser(['google_id' => 'o5', 'callsign' => 'Op5', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'H'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $hidden = MasterPortal::create(['guid' => 'g.hidden', 'title' => 'Disputed', 'lat' => 31.1, 'lng' => -81.4, 'status' => MasterPortal::HIDDEN]);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/waypoints", ['portal_id' => $hidden->id]);
        $this->assertSame(0, $op->waypoints()->count());
    }

    public function test_an_import_replaces_a_community_added_copy_of_the_same_portal(): void
    {
        $operative = $this->mkUser(['google_id' => 'o6', 'callsign' => 'Op6', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'M'.uniqid()]);
        $standIn = MasterPortal::create(['guid' => 'contrib:aaaa', 'title' => 'the fountain', 'lat' => 31.15, 'lng' => -81.49, 'status' => MasterPortal::UNVERIFIED, 'source' => 'contrib', 'parking' => 'lot behind']);
        $standIn->contributions()->create(['user_id' => $operative->id, 'title' => 'the fountain']);
        $farStandIn = MasterPortal::create(['guid' => 'contrib:bbbb', 'title' => 'a different place', 'lat' => 31.1505, 'lng' => -81.49, 'status' => MasterPortal::UNVERIFIED, 'source' => 'contrib']); // ~55 m away
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'the fountain', 'lat' => 31.15, 'lng' => -81.49, 'guid' => 'contrib:aaaa']);

        $result = MasterPortal::import([['guid' => 'real.16', 'title' => 'Memorial Fountain', 'lat' => 31.15005, 'lng' => -81.49003]], 'iitc-test'); // ~6 m away

        $this->assertSame(['added' => 1, 'updated' => 0, 'merged' => 1], $result);
        $this->assertDatabaseMissing('master_portals', ['id' => $standIn->id]);
        $this->assertDatabaseHas('master_portals', ['guid' => 'real.16', 'title' => 'Memorial Fountain', 'parking' => 'lot behind']); // its intel carried over
        $this->assertSame('real.16', $wp->fresh()->guid);           // the plan portal follows to the real one
        $this->assertSame('the fountain', $wp->fresh()->title);     // …and keeps the name its op gave it
        $this->assertDatabaseHas('master_portals', ['id' => $farStandIn->id]); // too far to be the same portal
        $this->assertSame(0, PortalContribution::count());
    }

    public function test_unlabelled_portals_get_their_county_one_lookup_per_square(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => function ($request) {
            return str_starts_with((string) $request['lat'], '30.3')
                ? Http::response(['address' => ['county' => 'Duval County', 'state' => 'Florida', 'ISO3166-2-lvl4' => 'US-FL', 'country' => 'United States']])
                : Http::response(['error' => 'Unable to geocode']);
        }]);
        MasterPortal::create(['guid' => 'j1', 'title' => 'A', 'lat' => 30.3301, 'lng' => -81.6501]);
        MasterPortal::create(['guid' => 'j2', 'title' => 'B', 'lat' => 30.3302, 'lng' => -81.6502]); // same square → no second lookup
        MasterPortal::create(['guid' => 'sea', 'title' => 'C', 'lat' => 25.0, 'lng' => -70.0]);      // no answer
        MasterPortal::create(['guid' => 'mine', 'title' => 'D', 'lat' => 30.3303, 'lng' => -81.6503, 'region' => 'My label']);

        $this->artisan('toady:catalog-regions')->assertSuccessful();

        $this->assertSame(['Duval County, FL', 'Duval County, FL'], MasterPortal::whereIn('guid', ['j1', 'j2'])->pluck('region')->all());
        $this->assertNull(MasterPortal::where('guid', 'sea')->value('region'));
        $this->assertSame('My label', MasterPortal::where('guid', 'mine')->value('region')); // never overwritten
        Http::assertSentCount(2);

        // the square with no answer isn't asked again the next night
        $this->artisan('toady:catalog-regions')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_a_county_the_owner_split_up_folds_into_the_nearest_of_their_regions(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(
            ['address' => ['county' => 'Glynn County', 'ISO3166-2-lvl4' => 'US-GA', 'country' => 'United States']])]);
        config(['services.toady.catalog_region_folds' => ['Glynn County, GA' => ['Brunswick / mainland', 'Jekyll Island']]]);
        MasterPortal::create(['guid' => 'b', 'title' => 'Town', 'lat' => 31.15, 'lng' => -81.49, 'region' => 'Brunswick / mainland']);
        MasterPortal::create(['guid' => 'j', 'title' => 'Beach', 'lat' => 31.06, 'lng' => -81.42, 'region' => 'Jekyll Island']);
        MasterPortal::create(['guid' => 'o', 'title' => 'Elsewhere', 'lat' => 31.151, 'lng' => -81.491, 'region' => 'Outside Glynn']); // nearest, but not one of the split
        MasterPortal::create(['guid' => 'new1', 'title' => 'New in town', 'lat' => 31.152, 'lng' => -81.492]);
        MasterPortal::create(['guid' => 'new2', 'title' => 'New on the island', 'lat' => 31.07, 'lng' => -81.41]);

        $this->artisan('toady:catalog-regions')->assertSuccessful();

        $this->assertSame('Brunswick / mainland', MasterPortal::where('guid', 'new1')->value('region'));
        $this->assertSame('Jekyll Island', MasterPortal::where('guid', 'new2')->value('region'));
        $this->assertSame(0, MasterPortal::where('region', 'Glynn County, GA')->count());
    }
}
