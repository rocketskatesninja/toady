<?php

namespace Tests\Feature;

use App\Models\MasterPortal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
