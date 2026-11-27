<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ops are addressed by an unguessable public_id, never the sequential integer PK — so an op URL
 * can't be enumerated and a non-member can't tell a real op from a missing one.
 */
class OpPublicIdTest extends TestCase
{
    use RefreshDatabase;

    private function op(int $ownerId, array $attrs = []): Op
    {
        return Op::create(array_merge([
            'owner_id' => $ownerId, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active',
            'join_token' => Op::freshToken(),
        ], $attrs));
    }

    public function test_every_op_gets_a_generated_unguessable_public_id(): void
    {
        $owner = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $a = $this->op($owner->id);
        $b = $this->op($owner->id);

        // 10 chars from the unambiguous base32 alphabet — not the numeric PK, and distinct per op.
        foreach ([$a, $b] as $op) {
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{10}$/', $op->public_id);
            $this->assertNotSame((string) $op->id, $op->public_id);
        }
        $this->assertNotSame($a->public_id, $b->public_id);
    }

    public function test_the_op_page_resolves_by_public_id_and_the_integer_pk_does_not(): void
    {
        $owner = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $op = $this->op($owner->id);
        $op->participants()->create(['user_id' => $owner->id, 'role' => 'operative']);

        $this->actingAs($owner)->get("/ops/{$op->public_id}")->assertOk();

        // Guessing the sequential PK gets the same soft landing as a link to a purged op — no oracle.
        $this->actingAs($owner)->get("/ops/{$op->id}")
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');
    }

    public function test_a_non_member_gets_the_soft_landing_not_a_403(): void
    {
        $owner = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $outsider = $this->mkUser(['callsign' => 'Nosy', 'faction' => 'RES']);
        $op = $this->op($owner->id);
        $op->participants()->create(['user_id' => $owner->id, 'role' => 'operative']);

        // Even holding the real public_id, a non-member can't tell the op exists — identical to a miss.
        $this->actingAs($outsider)->get("/ops/{$op->public_id}")
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');
    }

    public function test_notification_feed_filters_by_the_ops_public_id(): void
    {
        $owner = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $op = $this->op($owner->id);
        $op->participants()->create(['user_id' => $owner->id, 'role' => 'operative']);

        $mine = Notification::create(['user_id' => $owner->id, 'op_id' => $op->id, 'type' => 'go', 'title' => 'live', 'url' => "/ops/{$op->public_id}"]);
        Notification::create(['user_id' => $owner->id, 'op_id' => null, 'type' => 'report', 'title' => 'global']);

        $items = $this->actingAs($owner)->getJson("/notifications/feed?op={$op->public_id}")
            ->assertOk()->json('items');

        $this->assertSame([$mine->id], array_column($items, 'id'));
    }
}
