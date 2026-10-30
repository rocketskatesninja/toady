<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AfterActionTest extends TestCase
{
    use RefreshDatabase;

    /** The after-action report is computed client-side from each step's done_by + done_at — make sure both reach the page. */
    public function test_completed_steps_expose_done_by_and_done_at(): void
    {
        $operative = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Vec', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'A'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $step = $op->steps()->create(['text' => 'Hack the portal', 'action' => 'hack']);

        $this->actingAs($agent)->putJson("/ops/{$op->public_id}/steps/{$step->id}/toggle", ['done' => true])->assertNoContent();

        $this->actingAs($operative)->get("/ops/{$op->public_id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('steps.0.done', true)
                ->where('steps.0.done_by', $agent->id)
                ->where('steps.0.done_at', fn ($v) => $v !== null)
                ->etc()
            );
    }
}
