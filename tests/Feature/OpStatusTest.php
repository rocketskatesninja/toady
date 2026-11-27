<?php

namespace Tests\Feature;

use App\Models\Op;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpStatusTest extends TestCase
{
    use RefreshDatabase;

    private function planningOp(?User &$operative): Op
    {
        $operative = $this->mkUser(['google_id' => 's', 'callsign' => 'Lead', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Recon Run', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'ST'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);

        return $op;
    }

    public function test_flipping_status_flashes_a_confirmation_to_the_operator(): void
    {
        $op = $this->planningOp($operative);

        $this->actingAs($operative)->put("/ops/{$op->public_id}", ['status' => 'active'])
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'is now live'));

        $this->actingAs($operative)->put("/ops/{$op->public_id}", ['status' => 'complete'])
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'marked complete'));

        $this->actingAs($operative)->put("/ops/{$op->public_id}", ['status' => 'planning'])
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'back to planning'));
    }

    public function test_a_non_status_edit_or_a_no_op_status_flashes_nothing(): void
    {
        $op = $this->planningOp($operative);

        // editing the briefing (no status field) → no status banner
        $this->actingAs($operative)->put("/ops/{$op->public_id}", ['description' => 'brief'])
            ->assertRedirect()->assertSessionMissing('success');

        // re-submitting the SAME status → no change → no banner
        $this->actingAs($operative)->put("/ops/{$op->public_id}", ['status' => 'planning'])
            ->assertRedirect()->assertSessionMissing('success');
    }
}
