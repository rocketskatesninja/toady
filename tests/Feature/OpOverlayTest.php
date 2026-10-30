<?php

namespace Tests\Feature;

use App\Models\Op;
use App\Models\OpParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The op map's overlay: list your other ops, and fetch an op's bare shape (portals + links) under show()'s rules. */
class OpOverlayTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $callsign): User
    {
        return $this->mkUser(['google_id' => 'g-'.$callsign, 'callsign' => $callsign, 'faction' => 'ENL']);
    }

    private function op(User $owner, string $name, string $status = 'active', string $type = 'any_order'): Op
    {
        $op = Op::create(['owner_id' => $owner->id, 'name' => $name, 'type' => $type, 'status' => $status, 'join_token' => 'OV'.(++$this->n).uniqid()]);
        $op->participants()->create(['user_id' => $owner->id, 'role' => OpParticipant::ROLE_OPERATIVE]);

        return $op;
    }

    private function join(Op $op, User $user, string $role = OpParticipant::ROLE_AGENT): void
    {
        $op->participants()->create(['user_id' => $user->id, 'role' => $role]);
    }

    /** Alpha→Bravo, Alpha→Charlie, Bravo→Charlie (one field), plus a portal that was never placed. */
    private function plan(Op $op): array
    {
        $a = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'guid' => "g{$op->id}a", 'title' => 'Alpha', 'lat' => 31.15, 'lng' => -81.49, 'gate_pin' => '4242']);
        $b = $op->waypoints()->create(['seq' => 2, 'role' => 'spine', 'guid' => "g{$op->id}b", 'title' => 'Bravo', 'lat' => 31.16, 'lng' => -81.48]);
        $c = $op->waypoints()->create(['seq' => 3, 'role' => 'spine', 'guid' => "g{$op->id}c", 'title' => 'Charlie', 'lat' => 31.14, 'lng' => -81.47]);
        $op->waypoints()->create(['seq' => 4, 'role' => 'spine', 'title' => 'Unplaced']);
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'text' => 'Link out', 'action' => 'link', 'op_waypoint_id' => $a->id, 'links' => [$b->id, $c->id], 'notes' => 'secret plan note']);
        $op->steps()->create(['phase' => 'run', 'seq' => 2, 'text' => 'Close the field', 'action' => 'link', 'op_waypoint_id' => $b->id, 'links' => [$c->id], 'done' => true]);

        return [$a, $b, $c];
    }

    public function test_an_operative_gets_the_placed_portals_and_links_and_nothing_else(): void
    {
        $lead = $this->user('Lead');
        $op = $this->op($lead, 'Pearl');
        [$a, $b, $c] = $this->plan($op);

        $res = $this->actingAs($lead)->getJson("/ops/{$op->public_id}/outline")->assertOk();

        $this->assertSame(['id', 'name', 'status', 'portals', 'links'], array_keys($res->json()));
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_column($res->json('portals'), 'title')); // unplaced left out
        $this->assertSame(['id', 'title', 'lat', 'lng'], array_keys($res->json('portals.0')));
        $this->assertSame([[$a->id, $b->id, false], [$a->id, $c->id, false], [$b->id, $c->id, true]], $res->json('links'));
        $this->assertStringNotContainsString('secret plan note', $res->getContent());
        $this->assertStringNotContainsString('4242', $res->getContent());
    }

    public function test_outline_is_hidden_from_non_participants_and_from_agents_of_a_planning_op(): void
    {
        $lead = $this->user('Lead');
        $agent = $this->user('Agent');
        $stranger = $this->user('Stranger');
        $draft = $this->op($lead, 'Draft', 'planning');
        $this->plan($draft);
        $this->join($draft, $agent);

        $this->actingAs($stranger)->getJson("/ops/{$draft->public_id}/outline")->assertNotFound();
        $this->actingAs($agent)->getJson("/ops/{$draft->public_id}/outline")->assertNotFound();
        $this->actingAs($lead)->getJson("/ops/{$draft->public_id}/outline")->assertOk();
    }

    public function test_a_page_visit_still_lands_softly_but_a_background_fetch_gets_a_plain_404(): void
    {
        $lead = $this->user('Lead');
        $stranger = $this->user('Stranger');
        $op = $this->op($lead, 'Pearl');

        $this->actingAs($stranger)->get("/ops/{$op->public_id}")
            ->assertRedirect(route('dashboard'))->assertSessionHas('error');
        $this->actingAs($stranger)->getJson("/ops/{$op->public_id}/outline")->assertNotFound();
    }

    public function test_a_closed_op_has_no_outline(): void
    {
        $lead = $this->user('Lead');
        $op = $this->op($lead, 'Done', 'closed');

        $this->actingAs($lead)->getJson("/ops/{$op->public_id}/outline")->assertNotFound();
    }

    public function test_a_hidden_op_shows_its_agents_only_what_their_op_page_does(): void
    {
        $lead = $this->user('Lead');
        $agent = $this->user('Agent');
        $op = $this->op($lead, 'Secret', 'active', 'hidden');
        $this->join($op, $agent);
        $a = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'Alpha', 'lat' => 31.15, 'lng' => -81.49]);
        $b = $op->waypoints()->create(['seq' => 2, 'role' => 'spine', 'title' => 'Bravo', 'lat' => 31.16, 'lng' => -81.48]);
        $c = $op->waypoints()->create(['seq' => 3, 'role' => 'spine', 'title' => 'Charlie', 'lat' => 31.14, 'lng' => -81.47]);
        // Alpha is done, so the agent's front is Bravo: they may see Alpha + Bravo, never Charlie
        $op->steps()->create(['phase' => 'run', 'seq' => 1, 'text' => 'Link out', 'action' => 'link', 'op_waypoint_id' => $a->id, 'links' => [$b->id, $c->id], 'done' => true]);
        $op->steps()->create(['phase' => 'run', 'seq' => 2, 'text' => 'Link on', 'action' => 'link', 'op_waypoint_id' => $b->id, 'links' => [$c->id]]);

        $seen = $this->actingAs($agent)->getJson("/ops/{$op->public_id}/outline")->assertOk();
        $this->assertSame(['Alpha', 'Bravo'], array_column($seen->json('portals'), 'title'));
        $this->assertSame([[$a->id, $b->id, true]], $seen->json('links')); // links into Charlie stay hidden
        $this->assertStringNotContainsString('Charlie', $seen->getContent());

        $full = $this->actingAs($lead)->getJson("/ops/{$op->public_id}/outline")->assertOk();
        $this->assertCount(3, $full->json('portals'));
        $this->assertCount(3, $full->json('links'));
    }

    public function test_overlays_lists_the_viewers_other_live_ops_they_could_open(): void
    {
        $lead = $this->user('Lead');
        $other = $this->user('Other');
        $here = $this->op($lead, 'Here');
        $mine = $this->op($lead, 'Mine', 'planning');
        $this->plan($mine);
        $theirsActive = $this->op($other, 'Theirs active');
        $this->join($theirsActive, $lead);
        $theirsDraft = $this->op($other, 'Theirs draft', 'planning'); // lead is only an agent → can't see the draft
        $this->join($theirsDraft, $lead);
        $theirsDraftOps = $this->op($other, 'Theirs draft, co-op', 'planning');
        $this->join($theirsDraftOps, $lead, OpParticipant::ROLE_OPERATIVE);
        $this->op($lead, 'Closed', 'closed');
        $this->op($other, 'Not joined');

        $res = $this->actingAs($lead)->getJson("/ops/{$here->public_id}/overlays")->assertOk();

        $this->assertEqualsCanonicalizing(['Mine', 'Theirs active', 'Theirs draft, co-op'], array_column($res->json(), 'name'));
        $this->assertSame(['id', 'name', 'status', 'portals'], array_keys($res->json('0')));
        $this->assertSame(3, collect($res->json())->firstWhere('name', 'Mine')['portals']); // placed portals only
    }

    public function test_overlays_are_for_the_current_ops_operatives_only(): void
    {
        $lead = $this->user('Lead');
        $agent = $this->user('Agent');
        $stranger = $this->user('Stranger');
        $op = $this->op($lead, 'Here');
        $this->join($op, $agent);

        $this->actingAs($agent)->getJson("/ops/{$op->public_id}/overlays")->assertNotFound();
        $this->actingAs($stranger)->getJson("/ops/{$op->public_id}/overlays")->assertNotFound();
    }
}
