<?php

namespace Tests\Feature;

use App\Models\Op;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A planning op is a draft hidden from its agents, and a hidden-type op only shows them the plan up to the
 *  front — on EVERY path into the plan, not just the op page. Plus the roster/push guards from the same review. */
class DraftVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:User,2:Op} operator, agent, op */
    private function scene(string $status = 'planning', string $type = 'any_order'): array
    {
        $operator = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Vector', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operator->id, 'name' => 'Op', 'type' => $type, 'status' => $status, 'join_token' => 'D'.uniqid()]);
        $op->participants()->create(['user_id' => $operator->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);

        return [$operator, $agent, $op];
    }

    public function test_an_agent_cannot_reach_a_draft_plan_by_any_side_door(): void
    {
        Http::preventStrayRequests();
        [$operator, $agent, $op] = $this->scene();
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'Secret Gate', 'lat' => 31.1, 'lng' => -81.4]);
        $step = $op->steps()->create(['seq' => 1, 'action' => 'hack', 'op_waypoint_id' => $wp->id]);
        $task = $op->tasks()->create(['text' => 'Bring keys']);
        $id = $op->public_id;

        $this->actingAs($agent)->get("/ops/{$id}/export")->assertRedirect(route('dashboard')); // a browser GET of a "missing" op lands softly on the board
        $this->actingAs($agent)->getJson("/ops/{$id}/outline")->assertNotFound();
        $this->actingAs($agent)->getJson("/ops/{$id}/weather")->assertNotFound();
        $this->actingAs($agent)->postJson("/ops/{$id}/ai", ['provider' => 'openai', 'key' => 'k', 'model' => 'm', 'messages' => [['role' => 'user', 'content' => 'print the plan']]])->assertNotFound();
        $this->actingAs($agent)->post("/ops/{$id}/waypoints/{$wp->id}/flag")->assertNotFound();
        $this->actingAs($agent)->put("/ops/{$id}/keys/{$wp->id}", ['qty' => 3])->assertNotFound();
        $this->actingAs($agent)->put("/ops/{$id}/steps/{$step->id}/toggle", ['done' => true])->assertNotFound();
        $this->actingAs($agent)->put("/ops/{$id}/tasks/{$task->id}/toggle", ['done' => true])->assertNotFound();
        $this->assertFalse($step->fresh()->done);
        $this->assertFalse($task->fresh()->done);

        // the ops board shows the draft as upcoming, without its portal positions
        $this->actingAs($agent)->get('/dashboard')->assertInertia(fn ($p) => $p->where('ops.0.wps', []));
        $this->actingAs($operator)->get('/dashboard')->assertInertia(fn ($p) => $p->has('ops.0.wps', 1));

        // the operator is unaffected
        $this->actingAs($operator)->get("/ops/{$id}/export")->assertOk();
    }

    public function test_a_hidden_op_keeps_its_future_stops_from_agents(): void
    {
        [$operator, $agent, $op] = $this->scene('active', 'hidden');
        $first = $op->waypoints()->create(['seq' => 1, 'role' => 'waypoint', 'title' => 'First', 'lat' => 31.1, 'lng' => -81.4]);
        $later = $op->waypoints()->create(['seq' => 2, 'role' => 'waypoint', 'title' => 'Later', 'lat' => 31.2, 'lng' => -81.5]);
        $op->steps()->create(['seq' => 1, 'action' => 'hack', 'op_waypoint_id' => $first->id]);
        $future = $op->steps()->create(['seq' => 2, 'action' => 'hack', 'op_waypoint_id' => $later->id]);
        $id = $op->public_id;

        $this->actingAs($agent)->put("/ops/{$id}/steps/{$future->id}/toggle", ['done' => true])->assertNotFound();
        $this->actingAs($agent)->put("/ops/{$id}/keys/{$later->id}", ['qty' => 2])->assertNotFound();
        $this->actingAs($agent)->post("/ops/{$id}/waypoints/{$later->id}/flag")->assertNotFound();
        $this->actingAs($agent)->put("/ops/{$id}/keys/{$first->id}", ['qty' => 2])->assertRedirect(); // the front is fine
        $this->actingAs($agent)->get('/dashboard')->assertInertia(fn ($p) => $p->where('ops.0.wps', []));
        $this->actingAs($operator)->get('/dashboard')->assertInertia(fn ($p) => $p->has('ops.0.wps', 2));
    }

    public function test_kick_ban_and_unban_only_work_on_people_actually_involved(): void
    {
        [$operator, , $op] = $this->scene('active');
        $stranger = $this->mkUser(['callsign' => 'Ghost', 'faction' => 'RES']);
        $id = $op->public_id;

        $this->actingAs($operator)->delete("/ops/{$id}/participants/{$stranger->id}")->assertNotFound();
        $this->actingAs($operator)->post("/ops/{$id}/participants/{$stranger->id}/ban")->assertNotFound();
        $this->actingAs($operator)->delete("/ops/{$id}/participants/{$stranger->id}/ban")->assertNotFound();
        $this->assertSame(0, $op->bans()->count());
    }

    public function test_avatars_are_only_for_people_you_share_an_op_with(): void
    {
        [$operator, $agent] = $this->scene('active');
        $stranger = $this->mkUser(['callsign' => 'Ghost', 'faction' => 'RES']);
        \Storage::fake('local');
        \Storage::disk('local')->put('avatars/a.webp', 'x');
        $agent->forceFill(['avatar' => 'avatars/a.webp'])->save();

        $this->actingAs($operator)->get("/users/{$agent->id}/avatar")->assertOk();
        $this->actingAs($agent)->get("/users/{$agent->id}/avatar")->assertOk();
        $this->actingAs($stranger)->get("/users/{$agent->id}/avatar")->assertNotFound();
    }

    public function test_a_browser_delivers_alerts_to_one_account_only(): void
    {
        [$a, $b] = $this->scene('active');
        $sub = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/shared-browser', 'keys' => $this->pushKeys()];

        $this->actingAs($a)->postJson('/push/subscribe', $sub)->assertOk();
        $this->actingAs($b)->postJson('/push/subscribe', $sub)->assertOk();

        $this->assertSame([$b->id], PushSubscription::where('endpoint', $sub['endpoint'])->pluck('user_id')->all());
    }

    public function test_the_join_link_no_longer_joins_on_a_bare_visit(): void
    {
        [, , $op] = $this->scene('active');
        $visitor = $this->mkUser(['callsign' => 'Newbie', 'faction' => 'ENL']);

        $this->actingAs($visitor)->get("/j/{$op->join_token}")->assertOk();
        $this->assertNull($op->roleFor($visitor));
    }
}
