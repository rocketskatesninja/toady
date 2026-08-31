<?php

namespace Tests\Feature;

use App\Models\MasterPortal;
use App\Models\Op;
use App\Models\User;
use App\Support\CatalogContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogContributionTest extends TestCase
{
    use RefreshDatabase;

    private static int $n = 0;

    private function op(array $extra = []): User
    {
        self::$n++;

        return $this->mkUser(array_merge(['callsign' => 'Op'.self::$n, 'faction' => 'ENL'], $extra));
    }

    private function named(User $u, string $title, float $lat = 30.35, float $lng = -81.43, ?string $guid = null): void
    {
        CatalogContributor::contribute($u, ['title' => $title, 'lat' => $lat, 'lng' => $lng, 'guid' => $guid]);
    }

    public function test_first_contribution_creates_an_unverified_portal(): void
    {
        $this->named($this->op(), 'Sapelo Mural');
        $p = MasterPortal::firstWhere('title', 'Sapelo Mural');
        $this->assertNotNull($p);
        $this->assertSame('unverified', $p->status);
        $this->assertSame(1, $p->contributions()->count());
    }

    public function test_a_second_independent_operator_verifies_the_name(): void
    {
        $this->named($this->op(), 'Sapelo Mural');
        $this->named($this->op(), 'Sapelo Mural'); // same coords → same portal, agreeing
        $p = MasterPortal::firstWhere('title', 'Sapelo Mural');
        $this->assertSame('verified', $p->status);
        $this->assertSame(2, $p->contributions()->count());
    }

    public function test_a_trusted_contributor_verifies_on_a_single_submit(): void
    {
        $u = $this->op();
        $u->forceFill(['trusted' => true])->save();
        $this->named($u, 'Trusted Portal');
        $this->assertSame('verified', MasterPortal::firstWhere('title', 'Trusted Portal')->status);
    }

    public function test_one_vote_per_portal_a_rename_updates_not_duplicates(): void
    {
        $u = $this->op();
        $this->named($u, 'First Name');
        $this->named($u, 'Second Name'); // same user + coords → updates their single vote
        $p = MasterPortal::first();
        $this->assertSame(1, $p->contributions()->count());
        $this->assertSame('Second Name', $p->fresh()->title);
        $this->assertSame('unverified', $p->fresh()->status); // still one contributor
    }

    public function test_a_lone_challenger_cannot_override_a_verified_name(): void
    {
        $this->named($this->op(), 'Real Name');
        $this->named($this->op(), 'Real Name');  // 2 agree → verified
        $this->named($this->op(), 'Bogus Name'); // lone challenger
        $p = MasterPortal::first()->fresh();
        $this->assertSame('Real Name', $p->title); // consensus holds
        $this->assertSame('verified', $p->status);
    }

    public function test_guid_matches_the_same_portal_despite_coordinate_drift(): void
    {
        $g = 'abc123def456.16';
        $this->named($this->op(), 'Guid Portal', 10.0, 20.0, $g);
        $this->named($this->op(), 'Guid Portal', 10.02, 20.02, $g); // far coords, same guid
        $this->assertSame(1, MasterPortal::count());
        $this->assertSame('verified', MasterPortal::first()->status);
    }

    public function test_ineligible_users_contribute_nothing(): void
    {
        $this->named($this->mkUser(['faction' => 'ENL']), 'A');                                   // no callsign
        $this->named($this->mkUser(['callsign' => 'Un', 'faction' => 'ENL', 'email_verified_at' => null]), 'B'); // unverified email
        $sus = $this->mkUser(['callsign' => 'Sus', 'faction' => 'ENL']);
        $sus->forceFill(['suspended_at' => now()])->save(); // suspended_at isn't mass-assignable
        $this->named($sus, 'C');
        $this->assertSame(0, MasterPortal::count());
    }

    public function test_the_hourly_cap_blocks_a_flood(): void
    {
        $u = $this->op();
        for ($i = 0; $i < CatalogContributor::HOURLY_CAP; $i++) {
            $this->named($u, "P$i", 10 + $i * 0.02, 20.0); // distinct portals
        }
        $this->named($u, 'OverCap', 99.0, 20.0);
        $this->assertSame(CatalogContributor::HOURLY_CAP, MasterPortal::count());
        $this->assertNull(MasterPortal::firstWhere('title', 'OverCap'));
    }

    public function test_nearest_read_skips_hidden(): void
    {
        MasterPortal::create(['guid' => 'hx', 'title' => 'Hidden', 'lat' => 5.0, 'lng' => 6.0, 'status' => 'hidden']);
        $this->assertNull(MasterPortal::nearestTo(5.0, 6.0)->first());
    }

    public function test_enough_flags_hide_a_disputed_name(): void
    {
        $this->named($this->op(), 'Maybe Wrong'); // 1 contributor → unverified
        $p = MasterPortal::first();
        $p->flags()->create(['user_id' => $this->op()->id]);
        $p->recomputeFlagStatus();
        $this->assertSame('unverified', $p->fresh()->status); // 1 flag < 2
        $p->flags()->create(['user_id' => $this->op()->id]);
        $p->recomputeFlagStatus();
        $this->assertSame('hidden', $p->fresh()->status); // 2 flags ≥ 2 and ≥ contributors
    }

    public function test_owner_locked_names_are_not_community_hideable(): void
    {
        $p = MasterPortal::create(['guid' => 'ol', 'title' => 'Owner Name', 'lat' => 1, 'lng' => 1, 'status' => 'owner_locked']);
        $p->flags()->create(['user_id' => $this->op()->id]);
        $p->flags()->create(['user_id' => $this->op()->id]);
        $p->recomputeFlagStatus();
        $this->assertSame('owner_locked', $p->fresh()->status);
    }

    public function test_flagging_a_waypoint_flags_its_catalog_portal(): void
    {
        $operative = $this->op();
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'O', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'TOKF']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $portal = MasterPortal::create(['guid' => 'wpg.16', 'title' => 'Cataloged', 'lat' => 5, 'lng' => 5, 'status' => 'verified']);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'spine', 'guid' => 'wpg.16', 'title' => 'Cataloged', 'lat' => 5, 'lng' => 5]);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/waypoints/{$wp->id}/flag")->assertRedirect();
        $this->assertSame(1, $portal->flags()->count());
    }

    public function test_owner_locks_and_restores_portals(): void
    {
        $owner = $this->mkUser(['callsign' => 'Own', 'faction' => 'ENL']);
        $owner->forceFill(['is_owner' => true, 'is_admin' => true])->save();

        $p = MasterPortal::create(['guid' => 'lk', 'title' => 'X', 'lat' => 1, 'lng' => 1, 'status' => 'unverified']);
        $this->actingAs($owner)->post("/catalog/portals/{$p->id}/lock")->assertRedirect();
        $this->assertSame('owner_locked', $p->fresh()->status);

        $h = MasterPortal::create(['guid' => 'hd', 'title' => 'Y', 'lat' => 2, 'lng' => 2, 'status' => 'hidden']);
        $h->flags()->create(['user_id' => $this->op()->id]);
        $this->actingAs($owner)->post("/catalog/portals/{$h->id}/restore")->assertRedirect();
        $this->assertSame('verified', $h->fresh()->status);
        $this->assertSame(0, $h->flags()->count());
    }

    public function test_non_owner_cannot_lock_a_portal(): void
    {
        $p = MasterPortal::create(['guid' => 'nl', 'title' => 'Z', 'lat' => 1, 'lng' => 1, 'status' => 'unverified']);
        $this->actingAs($this->op())->post("/catalog/portals/{$p->id}/lock")->assertForbidden();
    }

    public function test_owner_grants_and_revokes_trusted(): void
    {
        $owner = $this->mkUser(['callsign' => 'Own2', 'faction' => 'ENL']);
        $owner->forceFill(['is_owner' => true, 'is_admin' => true])->save();
        $u = $this->op();

        $this->actingAs($owner)->post('/admin/users/bulk', ['action' => 'trust', 'ids' => [$u->id]])->assertRedirect();
        $this->assertTrue($u->fresh()->trusted);
        $this->actingAs($owner)->post('/admin/users/bulk', ['action' => 'untrust', 'ids' => [$u->id]])->assertRedirect();
        $this->assertFalse($u->fresh()->trusted);
    }

    public function test_in_view_returns_only_confirmed_portals_within_the_viewport(): void
    {
        MasterPortal::create(['guid' => 'v1', 'title' => 'In View Verified', 'lat' => 30.35, 'lng' => -81.43, 'status' => 'verified']);
        MasterPortal::create(['guid' => 'ol1', 'title' => 'Owner Locked', 'lat' => 30.36, 'lng' => -81.42, 'status' => 'owner_locked']);
        MasterPortal::create(['guid' => 'u1', 'title' => 'Unverified', 'lat' => 30.35, 'lng' => -81.43, 'status' => 'unverified']);
        MasterPortal::create(['guid' => 'h1', 'title' => 'Hidden', 'lat' => 30.35, 'lng' => -81.43, 'status' => 'hidden']);
        MasterPortal::create(['guid' => 'far', 'title' => 'Far Away', 'lat' => 10.0, 'lng' => 10.0, 'status' => 'verified']);

        $res = $this->actingAs($this->op())->getJson('/api/catalog/in-view?n=30.5&s=30.2&e=-81.3&w=-81.6');
        $res->assertOk();
        $titles = collect($res->json())->pluck('title')->sort()->values()->all();
        $this->assertSame(['In View Verified', 'Owner Locked'], $titles); // unverified/hidden/out-of-bounds excluded
    }

    public function test_naming_a_map_drop_in_an_op_contributes(): void
    {
        $operative = $this->op();
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'O', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'TOKX']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);

        $this->actingAs($operative)
            ->post("/ops/{$op->public_id}/waypoints", ['lat' => 12.34, 'lng' => 56.78, 'title' => 'Dropped Portal'])
            ->assertRedirect();

        $portal = MasterPortal::firstWhere('title', 'Dropped Portal');
        $this->assertNotNull($portal);
        // the waypoint is linked to the contributed portal (by GUID) so its trust badge renders
        $this->assertSame($portal->guid, $op->waypoints()->first()->guid);
    }
}
