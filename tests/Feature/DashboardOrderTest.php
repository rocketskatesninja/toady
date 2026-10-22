<?php

namespace Tests\Feature;

use App\Models\Op;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardOrderTest extends TestCase
{
    use RefreshDatabase;

    /** Make an op the user participates in. */
    private function op(User $u, string $name): Op
    {
        $op = Op::create(['owner_id' => $u->id, 'name' => $name, 'type' => 'any_order', 'status' => 'planning', 'join_token' => $name]);
        $op->participants()->create(['user_id' => $u->id, 'role' => 'operative']);

        return $op;
    }

    private function dashboardOpIds(User $u): array
    {
        return collect($this->actingAs($u)->get('/dashboard')->viewData('page')['props']['ops'])
            ->pluck('id')->all();
    }

    public function test_saved_order_reorders_the_dashboard_cards(): void
    {
        $u = $this->mkUser(['google_id' => 'o', 'callsign' => 'Lead', 'faction' => 'ENL']);
        $a = $this->op($u, 'A');
        $b = $this->op($u, 'B');
        $c = $this->op($u, 'C');

        // default is newest-first (created desc): C, B, A
        $this->assertSame([$c->public_id, $b->public_id, $a->public_id], $this->dashboardOpIds($u));

        // save an explicit manual order
        $this->actingAs($u)->put('/dashboard/order', ['order' => [$a->public_id, $c->public_id, $b->public_id]])->assertNoContent();
        $this->assertSame([$a->public_id, $c->public_id, $b->public_id], $this->dashboardOpIds($u));
    }

    public function test_new_op_absent_from_saved_order_sorts_to_the_top(): void
    {
        $u = $this->mkUser(['google_id' => 'o2', 'callsign' => 'Lead', 'faction' => 'ENL']);
        $a = $this->op($u, 'A');
        $b = $this->op($u, 'B');
        $this->actingAs($u)->put('/dashboard/order', ['order' => [$b->public_id, $a->public_id]])->assertNoContent();

        // a brand-new op isn't in the saved list → it lands at the top, saved order follows
        $d = $this->op($u, 'D');
        $this->assertSame([$d->public_id, $b->public_id, $a->public_id], $this->dashboardOpIds($u));
    }

    public function test_order_is_per_user_and_ignores_foreign_ids(): void
    {
        $u = $this->mkUser(['google_id' => 'o3', 'callsign' => 'Lead', 'faction' => 'ENL']);
        $other = $this->mkUser(['google_id' => 'o4', 'callsign' => 'Other', 'faction' => 'ENL']);
        $a = $this->op($u, 'A');
        $b = $this->op($u, 'B');

        // an id the user doesn't participate in is harmless — it's simply ignored when applied
        $this->actingAs($u)->put('/dashboard/order', ['order' => [$b->public_id, 'ZZZZZZZZZZ', $a->public_id]])->assertNoContent();
        $this->assertSame([$b->public_id, $a->public_id], $this->dashboardOpIds($u));

        // the other user is unaffected (their dashboard is empty; the order didn't bleed across)
        $this->assertSame([], $this->dashboardOpIds($other));
    }

    public function test_order_must_be_an_array(): void
    {
        $u = $this->mkUser(['google_id' => 'o5', 'callsign' => 'Lead', 'faction' => 'ENL']);
        $this->actingAs($u)->putJson('/dashboard/order', ['order' => 'nope'])->assertStatus(422);
    }
}
