<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapTrafficTest extends TestCase
{
    use RefreshDatabase;

    public function test_traffic_tiles_require_authentication(): void
    {
        $this->get('/map/traffic/10/512/512')->assertRedirect('/login');
    }

    public function test_traffic_tiles_404_when_no_key_is_configured(): void
    {
        config(['services.tomtom.key' => null]);
        $user = $this->mkUser(['callsign' => 'Scout', 'faction' => 'ENL']);
        $this->actingAs($user)->get('/map/traffic/10/512/512')->assertNotFound();
    }
}
