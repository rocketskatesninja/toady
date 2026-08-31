<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleOpLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_link_to_a_missing_op_lands_softly_on_the_dashboard(): void
    {
        $user = $this->mkUser(['callsign' => 'Agent', 'faction' => 'ENL']);

        $this->actingAs($user)->get('/ops/999999')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');
    }

    public function test_an_agents_bookmarked_link_is_soft_after_the_op_is_purged(): void
    {
        $owner = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Vec', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $owner->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'STZ']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $id = $op->public_id; // the agent's real bookmarked link

        $op->delete(); // close() purges the op the same way (FK cascade removes the agent's seat)

        $this->actingAs($agent)->get("/ops/{$id}")
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');
    }
}
