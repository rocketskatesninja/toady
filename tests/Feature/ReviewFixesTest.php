<?php

namespace Tests\Feature;

use App\Models\MasterPortal;
use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Bugs found by the Nov 2026 whole-site review. */
class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private function operatorWithOp(): array
    {
        $operator = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $op = Op::createFor($operator, ['name' => 'Op', 'type' => 'any_order']);

        return [$operator, $op];
    }

    public function test_going_live_drops_the_undo_history_so_undo_can_never_roll_back_a_run(): void
    {
        [$operator, $op] = $this->operatorWithOp();
        $this->actingAs($operator)->post("/ops/{$op->public_id}/waypoints", ['title' => 'Gate']);
        $this->assertGreaterThan(0, $op->undoSnapshots()->count());

        $this->actingAs($operator)->put("/ops/{$op->public_id}", ['status' => 'active']);

        $this->assertSame(0, $op->undoSnapshots()->count());
    }

    public function test_a_plan_file_is_bounded_like_a_form_and_drops_directives_that_point_nowhere(): void
    {
        $operator = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $plan = [
            'name' => 'Imported', 'description' => str_repeat('d', 5000),
            'waypoints' => [
                ['seq' => 1, 'title' => 'Real', 'lat' => 31.1, 'lng' => -81.4],
                ['seq' => 2, 'title' => 'Off the map', 'lat' => 999, 'lng' => -81.4],
                'not-an-array',
            ],
            'steps' => [
                ['seq' => 1, 'waypoint_seq' => 1, 'action' => 'hack'],
                ['seq' => 2, 'waypoint_seq' => 1, 'action' => 'made-up-action', 'text' => 'kept for its text'],
                ['seq' => 3, 'waypoint_seq' => 99, 'action' => 'hack'],               // no such location
                ['seq' => 4, 'waypoint_seq' => ['x'], 'link_seqs' => 'nope', 'action' => 'link'], // malformed
            ],
        ];

        $this->actingAs($operator)->post('/ops/import', ['plan' => json_encode($plan)])->assertRedirect();

        $op = Op::where('name', 'Imported')->firstOrFail();
        $this->assertSame(2000, mb_strlen($op->description));
        $this->assertNull($op->waypoints()->where('title', 'Off the map')->first()->lat); // kept, unplaced
        $this->assertSame(2, $op->steps()->count());
        $this->assertSame(0, $op->steps()->whereNull('op_waypoint_id')->count());
        $this->assertNull($op->steps()->where('text', 'kept for its text')->first()->action);
    }

    public function test_deleting_a_user_removes_their_photo_however_they_are_deleted(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('avatars/x.jpg', 'x');
        $user = $this->mkUser(['callsign' => 'Gone', 'faction' => 'ENL']);
        $user->forceFill(['avatar' => 'avatars/x.jpg'])->save();

        $user->delete();

        Storage::disk('local')->assertMissing('avatars/x.jpg');
    }

    public function test_a_hidden_catalog_name_is_not_found_by_guid_either(): void
    {
        MasterPortal::create(['guid' => 'g-1', 'title' => 'Wrong Name', 'lat' => 31.1, 'lng' => -81.4, 'status' => MasterPortal::HIDDEN]);

        $this->assertNull(MasterPortal::locate('g-1', null, null));
    }

    public function test_an_agents_emergency_contact_is_shown_to_the_ops_operators_only(): void
    {
        [$operator, $op] = $this->operatorWithOp();
        $op->update(['status' => 'active']);
        $agent = $this->mkUser(['callsign' => 'Vector', 'faction' => 'ENL', 'emergency_contact' => 'Sam 555-0100']);
        $other = $this->mkUser(['callsign' => 'Nova', 'faction' => 'ENL']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $op->participants()->create(['user_id' => $other->id, 'role' => 'agent']);
        $mine = fn ($p) => collect($p->toArray()['props']['participants'])->firstWhere('user_id', $agent->id)['emergency'];

        $this->actingAs($operator)->get("/ops/{$op->public_id}")->assertInertia(fn ($p) => $this->assertSame('Sam 555-0100', $mine($p)));
        $this->actingAs($other)->get("/ops/{$op->public_id}")->assertInertia(fn ($p) => $this->assertNull($mine($p)));
    }
}
