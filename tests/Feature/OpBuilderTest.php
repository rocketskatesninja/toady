<?php

namespace Tests\Feature;

use App\Models\MasterPortal;
use App\Models\Op;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpBuilderTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:User,2:Op} operative, agent, op */
    private function scene(): array
    {
        $operative = $this->mkUser(['google_id' => 'o', 'callsign' => 'Lead', 'faction' => 'ENL']);
        $agent = $this->mkUser(['google_id' => 'a', 'callsign' => 'Grunt', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'TKN']);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);

        return [$operative, $agent, $op];
    }

    public function test_operative_adds_a_waypoint_from_the_catalog_with_snapshot_intel(): void
    {
        [$operative, , $op] = $this->scene();
        $portal = MasterPortal::create(['guid' => 'g1', 'title' => 'St Marks', 'lat' => 31.15, 'lng' => -81.49, 'gate_pin' => '4242', 'parking' => 'street']);

        $this->actingAs($operative)->post("/ops/{$op->public_id}/waypoints", ['portal_id' => $portal->id, 'role' => 'anchor']);

        $this->assertDatabaseHas('op_waypoints', [
            'op_id' => $op->id, 'title' => 'St Marks', 'role' => 'anchor', 'gate_pin' => '4242', 'parking' => 'street',
        ]);
    }

    public function test_map_drop_adds_a_waypoint_from_raw_coords(): void
    {
        [$operative, , $op] = $this->scene();
        $this->actingAs($operative)->post("/ops/{$op->public_id}/waypoints", ['lat' => 31.2, 'lng' => -81.5, 'title' => 'Dropped']);
        $this->assertDatabaseHas('op_waypoints', ['op_id' => $op->id, 'title' => 'Dropped']);
    }

    public function test_blank_title_map_drop_falls_through_to_auto_name(): void
    {
        // nameFor() returns null under tests, so a blank title must NOT be saved
        // as "" — it falls through to the (best-effort) auto-name lookup.
        [$operative, , $op] = $this->scene();
        $this->actingAs($operative)->post("/ops/{$op->public_id}/waypoints", ['lat' => 31.3, 'lng' => -81.6, 'title' => '   ']);
        $wp = $op->waypoints()->firstWhere('lat', 31.3);
        $this->assertNotNull($wp);
        $this->assertNull($wp->title, 'blank title is not persisted as an empty string');
    }

    public function test_operative_renames_a_waypoint(): void
    {
        [$operative, , $op] = $this->scene();
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'spine', 'title' => 'untitled thing', 'lat' => 31.2, 'lng' => -81.5]);

        $this->actingAs($operative)->put("/ops/{$op->public_id}/waypoints/{$wp->id}", ['title' => 'The Big Mural'])->assertRedirect();

        $this->assertSame('The Big Mural', $op->waypoints()->find($wp->id)->title);
    }

    public function test_agent_cannot_rename_a_waypoint(): void
    {
        [, $agent, $op] = $this->scene();
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'spine', 'title' => 'Orig', 'lat' => 31.2, 'lng' => -81.5]);

        $this->actingAs($agent)->put("/ops/{$op->public_id}/waypoints/{$wp->id}", ['title' => 'Hacked'])->assertForbidden();

        $this->assertSame('Orig', $op->waypoints()->find($wp->id)->title, 'agent edit rejected');
    }

    public function test_clear_all_directives_deletes_every_step_but_keeps_locations(): void
    {
        [$operative, $agent, $op] = $this->scene();
        $a = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'A', 'lat' => 31.2, 'lng' => -81.5]);
        $b = $op->waypoints()->create(['seq' => 2, 'role' => 'waypoint', 'title' => 'B', 'lat' => 31.1, 'lng' => -81.4, 'keys_needed' => 3]);
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'hack', 'op_waypoint_id' => $a->id]);
        $op->steps()->create(['phase' => 'run', 'seq' => 2, 'action' => 'link', 'op_waypoint_id' => $b->id, 'links' => [$a->id]]);

        // an agent can't clear the plan
        $this->actingAs($agent)->post("/ops/{$op->public_id}/steps/clear")->assertForbidden();
        $this->assertSame(2, $op->steps()->count());

        // the operative clears — directives gone, locations + key needs kept
        $this->actingAs($operative)->post("/ops/{$op->public_id}/steps/clear")->assertRedirect();
        $this->assertSame(0, $op->steps()->count(), 'all directives deleted');
        $this->assertSame(2, $op->waypoints()->count(), 'locations kept');
        $this->assertSame(3, (int) $op->waypoints()->find($b->id)->keys_needed, 'key needs are not directives — kept');

        // locked once the op is active
        $op->update(['status' => 'active']);
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'hack', 'op_waypoint_id' => $a->id]);
        $this->actingAs($operative)->post("/ops/{$op->public_id}/steps/clear")->assertStatus(409);
        $this->assertSame(1, $op->steps()->count());
    }

    public function test_reorder_moves_any_directive_regardless_of_phase(): void
    {
        [$operative, , $op] = $this->scene();
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'Anchor', 'lat' => 1, 'lng' => 1]);
        // a mixed list: a 'run' directive plus a legacy 'prep' farm-keys one (as auto-farm used to create)
        $hack = $op->steps()->create(['phase' => 'run', 'seq' => 1, 'action' => 'hack', 'op_waypoint_id' => $wp->id]);
        $farm = $op->steps()->create(['phase' => 'prep', 'seq' => 2, 'action' => 'farm keys', 'qty' => 3, 'op_waypoint_id' => $wp->id]);

        // move the prep farm-keys directive above the run one — this used to be a no-op (phase-filtered)
        $this->actingAs($operative)->post("/ops/{$op->public_id}/steps/reorder", ['order' => [$farm->id, $hack->id]])->assertRedirect();

        $this->assertSame(1, (int) $farm->fresh()->seq);
        $this->assertSame(2, (int) $hack->fresh()->seq);
    }

    public function test_agent_cannot_build_but_can_check_off_a_step(): void
    {
        [$operative, $agent, $op] = $this->scene();
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'Anchor', 'lat' => 1, 'lng' => 1]);

        $this->actingAs($agent)->post("/ops/{$op->public_id}/waypoints", ['lat' => 1, 'lng' => 1])->assertForbidden();

        $this->actingAs($operative)->post("/ops/{$op->public_id}/steps", ['phase' => 'run', 'text' => 'Hack anchor', 'op_waypoint_id' => $wp->id]);
        $step = $op->steps()->first();

        $this->actingAs($agent)->put("/ops/{$op->public_id}/steps/{$step->id}/toggle", ['done' => true]);
        $this->assertDatabaseHas('op_steps', ['id' => $step->id, 'done' => true, 'done_by' => $agent->id]);
    }

    public function test_closing_an_op_purges_everything(): void
    {
        [$operative, $agent, $op] = $this->scene();
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'lat' => 1, 'lng' => 1]);
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'text' => 'x']);
        $op->messages()->create(['user_id' => $operative->id, 'body' => 'hi']);
        $op->keyHoldings()->create(['op_waypoint_id' => $wp->id, 'user_id' => $agent->id, 'qty' => 3]);
        $op->presence()->create(['user_id' => $agent->id, 'lat' => 1, 'lng' => 1, 'sharing' => true]);

        $this->actingAs($operative)->delete("/ops/{$op->public_id}")->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('ops', ['id' => $op->id]);
        // every op-scoped table is gone — the privacy promise, asserted exhaustively (directive templates are
        // user-owned, not op-scoped, so they deliberately survive — covered in OpStepTemplateTest)
        foreach (['op_participants', 'op_waypoints', 'op_steps', 'op_messages', 'op_key_holdings', 'op_presence'] as $table) {
            $this->assertDatabaseMissing($table, ['op_id' => $op->id]);
        }
    }

    public function test_kicking_an_agent_scrubs_their_location_and_key_reports(): void
    {
        [$operative, $agent, $op] = $this->scene();
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'lat' => 1, 'lng' => 1]);
        $op->presence()->create(['user_id' => $agent->id, 'lat' => 1, 'lng' => 1, 'sharing' => true]);
        $op->keyHoldings()->create(['op_waypoint_id' => $wp->id, 'user_id' => $agent->id, 'qty' => 2]);

        $this->actingAs($operative)->delete("/ops/{$op->public_id}/participants/{$agent->id}")->assertRedirect();

        $this->assertDatabaseMissing('op_presence', ['op_id' => $op->id, 'user_id' => $agent->id]);
        $this->assertDatabaseMissing('op_key_holdings', ['op_id' => $op->id, 'user_id' => $agent->id]);
        $this->assertDatabaseHas('ops', ['id' => $op->id]); // the op + others' data are untouched
    }

    public function test_an_agent_can_leave_an_op_and_scrubs_their_footprint_but_the_owner_cannot(): void
    {
        [$operative, $agent, $op] = $this->scene();
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'lat' => 1, 'lng' => 1]);
        $op->presence()->create(['user_id' => $agent->id, 'lat' => 1, 'lng' => 1, 'sharing' => true]);
        $op->keyHoldings()->create(['op_waypoint_id' => $wp->id, 'user_id' => $agent->id, 'qty' => 2]);

        $this->actingAs($agent)->delete("/ops/{$op->public_id}/leave")->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('op_participants', ['op_id' => $op->id, 'user_id' => $agent->id]);
        $this->assertDatabaseMissing('op_presence', ['op_id' => $op->id, 'user_id' => $agent->id]);
        $this->assertDatabaseMissing('op_key_holdings', ['op_id' => $op->id, 'user_id' => $agent->id]);
        $this->assertDatabaseHas('ops', ['id' => $op->id]); // the op survives

        // the owner can't leave their own op (they close it instead)
        $this->actingAs($operative)->delete("/ops/{$op->public_id}/leave")->assertForbidden();
        $this->assertDatabaseHas('op_participants', ['op_id' => $op->id, 'user_id' => $operative->id]);
    }

    public function test_outsiders_cannot_view_an_op(): void
    {
        [, , $op] = $this->scene();
        $outsider = $this->mkUser(['google_id' => 'x', 'callsign' => 'Nope', 'faction' => 'RES']);
        // a non-member GET lands softly on the dashboard — deliberately indistinguishable from a missing op
        $this->actingAs($outsider)->get("/ops/{$op->public_id}")
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');
    }

    public function test_a_participant_has_a_private_per_op_notepad(): void
    {
        [$operative, $agent, $op] = $this->scene();
        $op->update(['status' => 'active']);

        // the agent jots a private note → persists on their own participant row
        $this->actingAs($agent)->put("/ops/{$op->public_id}/notes", ['notes' => 'meet at gate 0800'])->assertNoContent();
        $this->assertDatabaseHas('op_participants', ['op_id' => $op->id, 'user_id' => $agent->id, 'notes' => 'meet at gate 0800']);

        // each participant only ever receives their OWN notes — the operative never sees the agent's
        $this->actingAs($agent)->get("/ops/{$op->public_id}")->assertInertia(fn ($p) => $p->where('myNotes', 'meet at gate 0800'));
        $this->actingAs($operative)->get("/ops/{$op->public_id}")->assertInertia(fn ($p) => $p->where('myNotes', null));

        // an outsider can't write into the op's notes
        $outsider = $this->mkUser(['google_id' => 'zz', 'callsign' => 'Out', 'faction' => 'RES']);
        $this->actingAs($outsider)->put("/ops/{$op->public_id}/notes", ['notes' => 'x'])->assertForbidden();
    }

    public function test_operator_shares_op_wide_notes_that_agents_read_but_cannot_write(): void
    {
        [$operative, $agent, $op] = $this->scene();
        $op->update(['status' => 'active']);

        // operator posts op-wide notes
        $this->actingAs($operative)->put("/ops/{$op->public_id}/notes", ['notes' => 'rally at the pier 0800', 'scope' => 'op'])->assertNoContent();
        $this->assertSame('rally at the pier 0800', $op->fresh()->shared_notes);

        // everyone on the op sees them (carried on the op payload)
        $this->actingAs($agent)->get("/ops/{$op->public_id}")->assertInertia(fn ($p) => $p->where('op.shared_notes', 'rally at the pier 0800'));

        // an agent cannot write op-wide notes, but their private notes still work
        $this->actingAs($agent)->put("/ops/{$op->public_id}/notes", ['notes' => 'nope', 'scope' => 'op'])->assertForbidden();
        $this->assertSame('rally at the pier 0800', $op->fresh()->shared_notes); // unchanged
        $this->actingAs($agent)->put("/ops/{$op->public_id}/notes", ['notes' => 'my own', 'scope' => 'mine'])->assertNoContent();
        $this->assertDatabaseHas('op_participants', ['op_id' => $op->id, 'user_id' => $agent->id, 'notes' => 'my own']);
    }
}
