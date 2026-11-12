<?php

namespace Tests\Feature;

use App\Models\Op;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_op_view_never_exposes_a_participants_google_email(): void
    {
        $owner = $this->mkUser(['google_id' => 'o', 'callsign' => 'Lead', 'faction' => 'ENL', 'email' => 'owner.id@gmail.com']);
        $agent = $this->mkUser(['google_id' => 'a', 'callsign' => 'Vector', 'faction' => 'ENL', 'email' => 'agent.id@gmail.com', 'phone' => '555-0100']);
        $op = Op::create(['owner_id' => $owner->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'T']);
        $op->participants()->create(['user_id' => $owner->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);

        $this->actingAs($owner)->get("/ops/{$op->public_id}")
            ->assertOk()
            ->assertDontSee('agent.id@gmail.com')   // OAuth identity stays private
            ->assertDontSee('owner.id@gmail.com')
            ->assertSee('555-0100');                // opt-in contact info IS shared
    }
}
