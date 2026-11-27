<?php

namespace Tests\Feature;

use App\Models\HackTimer;
use App\Models\Notification;
use App\Models\Op;
use App\Models\PushSubscription;
use App\Support\Notifier;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HackTimerTest extends TestCase
{
    use RefreshDatabase;

    /** An active op with an operative, an agent, and one portal. */
    private function scene(string $status = 'active', string $type = 'any_order'): array
    {
        $operative = $this->mkUser(['callsign' => 'Lead'.substr(uniqid(), -5), 'faction' => 'ENL']);
        $agent = $this->mkUser(['callsign' => 'Vec'.substr(uniqid(), -5), 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $operative->id, 'name' => 'Op', 'type' => $type, 'status' => $status, 'join_token' => 'H'.uniqid()]);
        $op->participants()->create(['user_id' => $operative->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $agent->id, 'role' => 'agent']);
        $wp = $op->waypoints()->create(['seq' => 1, 'role' => 'anchor', 'title' => 'Lighthouse', 'lat' => 31.1, 'lng' => -81.4]);

        return [$operative, $agent, $op, $wp];
    }

    private function opTimer($user, Op $op, $wp): HackTimer
    {
        $this->actingAs($user)->post("/ops/{$op->public_id}/hacks", ['op_waypoint_id' => $wp->id])->assertRedirect();

        return HackTimer::where('user_id', $user->id)->where('op_waypoint_id', $wp->id)->firstOrFail();
    }

    public function test_standalone_timers_need_no_portal_and_get_stable_names(): void
    {
        $agent = $this->mkUser(['callsign' => 'Solo', 'faction' => 'RES']);

        $this->actingAs($agent)->post('/hacks', [])->assertRedirect();
        $this->actingAs($agent)->post('/hacks', ['label' => 'Fountain'])->assertRedirect();
        $this->actingAs($agent)->post('/hacks', [])->assertRedirect();
        $this->assertSame(['Timer 1', 'Fountain', 'Timer 2'], HackTimer::orderBy('id')->pluck('label')->all());
        $this->assertNull(HackTimer::first()->op_id);

        // deleting Timer 1 doesn't renumber Timer 2; the next new one reuses the free name
        HackTimer::where('label', 'Timer 1')->first()->delete();
        $this->actingAs($agent)->post('/hacks', [])->assertRedirect();
        $this->assertSame(['Fountain', 'Timer 2', 'Timer 1'], HackTimer::orderBy('id')->pluck('label')->all());

        // clearing a nickname falls back to an auto name
        $fountain = HackTimer::where('label', 'Fountain')->first();
        $this->actingAs($agent)->put("/hacks/{$fountain->id}", ['label' => ''])->assertRedirect();
        $this->assertSame('Timer 3', $fountain->fresh()->label);
    }

    public function test_op_timer_picks_a_portal_and_is_one_per_portal(): void
    {
        [, $agent, $op, $wp] = $this->scene();

        $timer = $this->opTimer($agent, $op, $wp);
        $this->assertSame([$op->id, 'Lighthouse'], [$timer->op_id, $timer->label]);

        $this->opTimer($agent, $op, $wp); // picking it again reuses the timer
        $this->assertSame(1, HackTimer::count());
    }

    public function test_agents_cannot_use_the_op_tool_until_the_op_is_live(): void
    {
        [$operative, $agent, $op, $wp] = $this->scene('planning');

        $this->actingAs($agent)->post("/ops/{$op->public_id}/hacks", ['op_waypoint_id' => $wp->id])->assertForbidden();
        $this->opTimer($operative, $op, $wp); // the operative can, while planning

        $op->update(['status' => 'active']);
        $this->opTimer($agent, $op, $wp);
        $this->assertSame(2, HackTimer::count());
    }

    public function test_op_timer_rejects_outsiders_foreign_portals_and_hidden_portals(): void
    {
        [, $agent, $op, $wp] = $this->scene();
        [, , , $otherWp] = $this->scene();
        $outsider = $this->mkUser(['callsign' => 'Out', 'faction' => 'RES']);

        $this->actingAs($outsider)->post("/ops/{$op->public_id}/hacks", ['op_waypoint_id' => $wp->id])->assertNotFound();
        $this->actingAs($agent)->post("/ops/{$op->public_id}/hacks", ['op_waypoint_id' => $otherWp->id])->assertSessionHasErrors('op_waypoint_id');

        // hidden op: the agent sees only the current portal, so the next one can't be picked yet
        [, $hAgent, $hidden, $wp1] = $this->scene('active', 'hidden');
        $wp2 = $hidden->waypoints()->create(['seq' => 2, 'role' => 'target', 'title' => 'Secret', 'lat' => 31.2, 'lng' => -81.5]);
        $hidden->steps()->create(['seq' => 1, 'op_waypoint_id' => $wp1->id, 'action' => 'hack']);
        $hidden->steps()->create(['seq' => 2, 'op_waypoint_id' => $wp2->id, 'action' => 'hack']);
        $this->actingAs($hAgent)->post("/ops/{$hidden->public_id}/hacks", ['op_waypoint_id' => $wp2->id])->assertNotFound();
        $this->opTimer($hAgent, $hidden, $wp1);
    }

    public function test_hacks_fill_the_window_which_resets_four_hours_after_the_first(): void
    {
        $agent = $this->mkUser(['callsign' => 'Solo', 'faction' => 'RES']);
        $timer = HackTimer::create(['user_id' => $agent->id, 'label' => 'T']);

        Carbon::setTestNow('2026-11-03 10:00:00');
        foreach (range(1, 4) as $_) {
            $this->actingAs($agent)->post("/hacks/{$timer->id}/hack")->assertRedirect();
        }
        $p = $timer->fresh()->toPayload();
        $this->assertSame([4, 4, 300], [count($p['hacks']), $p['hack_limit'], $p['cooldown_s']]);
        $this->assertSame('2026-11-03T14:00:00+00:00', $p['window_ends_at']);

        // a 5th hack is still recorded (never refused) …
        $this->actingAs($agent)->post("/hacks/{$timer->id}/hack")->assertRedirect();
        $this->assertCount(5, $timer->fresh()->hacks);
        // … and undo takes it back
        $this->actingAs($agent)->post("/hacks/{$timer->id}/undo")->assertRedirect();
        $this->assertCount(4, $timer->fresh()->hacks);

        // 4 h after the first hack the window is over: the payload shows it empty, the next hack starts fresh
        Carbon::setTestNow('2026-11-03 14:00:01');
        $this->assertSame([], $timer->fresh()->toPayload()['hacks']);
        $this->actingAs($agent)->post("/hacks/{$timer->id}/hack")->assertRedirect();
        $this->assertSame(['2026-11-03T14:00:01+00:00'], $timer->fresh()->hacks);
        Carbon::setTestNow();
    }

    public function test_slots_set_the_math_and_adding_a_heat_sink_resets_the_clock(): void
    {
        $agent = $this->mkUser(['callsign' => 'Solo', 'faction' => 'RES']);
        $timer = HackTimer::create(['user_id' => $agent->id, 'label' => 'T', 'hacks' => [now()->toIso8601String()]]);
        $slot = fn (int $i, ?string $mod) => $this->actingAs($agent)->put("/hacks/{$timer->id}/slot", ['slot' => $i, 'mod' => $mod]);
        $this->assertSame([null, null, null, null], $timer->fresh()->mods);

        $this->actingAs($agent)->put("/hacks/{$timer->id}", ['friendly' => true])->assertRedirect();
        $slot(0, 'multi_hack:very_rare')->assertRedirect();
        $slot(2, 'multi_hack:rare')->assertRedirect();
        $t = $timer->fresh();
        $this->assertSame(['multi_hack:very_rare', null, 'multi_hack:rare', null], $t->mods); // each mod stays in its slot
        $this->assertSame([180, 20], [$t->toPayload()['cooldown_s'], $t->toPayload()['hack_limit']]);
        $this->assertCount(1, $t->hacks); // Multi-hacks don't touch the clock

        // a Heat Sink into a slot that had none = you installed it → your cooldown + burnout reset
        $slot(1, 'heat_sink:rare')->assertRedirect();
        $this->assertSame([], $timer->fresh()->hacks);
        $this->assertSame(90, $timer->fresh()->toPayload()['cooldown_s']);

        // changing its rarity is a correction, not a new install — no reset; nor is emptying a slot
        $this->actingAs($agent)->post("/hacks/{$timer->id}/hack")->assertRedirect();
        $slot(1, 'heat_sink:very_rare')->assertRedirect();
        $this->assertCount(1, $timer->fresh()->hacks);
        $this->assertSame(54, $timer->fresh()->toPayload()['cooldown_s']); // 180 × 0.30
        $slot(1, null)->assertRedirect();
        $this->assertCount(1, $timer->fresh()->hacks);
        $this->assertSame(180, $timer->fresh()->toPayload()['cooldown_s']);

        // a Heat Sink replacing a Multi-hack is a new install → reset
        $slot(2, 'heat_sink:common')->assertRedirect();
        $this->assertSame([[], 16], [$timer->fresh()->hacks, $timer->fresh()->toPayload()['hack_limit']]);

        // only 4 slots, only the timer mods
        $slot(4, 'heat_sink:rare')->assertSessionHasErrors('slot');
        $slot(0, 'shield:rare')->assertSessionHasErrors('mod');
    }

    public function test_op_timer_keys_move_keys_mine_and_fire_fully_keyed_once(): void
    {
        [$operative, $agent, $op, $wp] = $this->scene();
        $wp->update(['keys_needed' => 2]);
        $timer = $this->opTimer($agent, $op, $wp);
        $keyed = fn () => Notification::where('user_id', $operative->id)->where('type', 'keys')->count();

        $this->actingAs($agent)->put("/hacks/{$timer->id}/keys", ['delta' => 1])->assertRedirect();
        $this->actingAs($agent)->put("/hacks/{$timer->id}/keys", ['delta' => 1])->assertRedirect();
        $this->assertSame(2, $timer->fresh()->keys);
        $this->assertSame(2, $op->heldKeys($agent, $wp));
        $this->assertSame(1, $keyed());

        // −1 comes back off both; neither goes below zero
        foreach (range(1, 3) as $_) {
            $this->actingAs($agent)->put("/hacks/{$timer->id}/keys", ['delta' => -1])->assertRedirect();
        }
        $this->assertSame([0, 0], [$timer->fresh()->keys, $op->heldKeys($agent, $wp)]);
        $this->actingAs($agent)->put("/hacks/{$timer->id}/keys", ['delta' => 5])->assertSessionHasErrors('delta');
    }

    public function test_keys_on_a_kicked_agents_timer_leave_the_op_alone(): void
    {
        [, $agent, $op, $wp] = $this->scene();
        $timer = $this->opTimer($agent, $op, $wp);
        $op->participants()->where('user_id', $agent->id)->delete();

        $this->actingAs($agent)->put("/hacks/{$timer->id}/keys", ['delta' => 1])->assertRedirect();
        $this->assertSame(1, $timer->fresh()->keys);
        $this->assertSame(0, $op->keyHoldings()->count());
    }

    public function test_standalone_keys_touch_no_op(): void
    {
        [, $agent, $op] = $this->scene();
        $this->actingAs($agent)->post('/hacks', [])->assertRedirect();
        $timer = HackTimer::firstOrFail();

        $this->actingAs($agent)->put("/hacks/{$timer->id}/keys", ['delta' => 1])->assertRedirect();
        $this->assertSame(1, $timer->fresh()->keys);
        $this->assertSame(0, $op->keyHoldings()->count());
    }

    public function test_closing_the_op_keeps_the_timer_and_its_portal_name(): void
    {
        [$operative, $agent, $op, $wp] = $this->scene();
        $timer = $this->opTimer($agent, $op, $wp);

        $this->actingAs($operative)->delete("/ops/{$op->public_id}")->assertRedirect();
        $t = $timer->fresh();
        $this->assertSame([null, null, 'Lighthouse'], [$t->op_id, $t->op_waypoint_id, $t->label]);
        $this->assertSame('Lighthouse', $t->toPayload()['label']);
    }

    public function test_timers_are_private_to_their_agent(): void
    {
        [$operative, $agent, $op, $wp] = $this->scene();
        $mine = $this->opTimer($agent, $op, $wp);
        $this->opTimer($operative, $op, $wp);

        foreach (['post' => ["/hacks/{$mine->id}/hack", "/hacks/{$mine->id}/undo"], 'put' => ["/hacks/{$mine->id}", "/hacks/{$mine->id}/keys", "/hacks/{$mine->id}/slot"], 'delete' => ["/hacks/{$mine->id}"]] as $verb => $urls) {
            foreach ($urls as $url) {
                $this->actingAs($operative)->{$verb}($url, ['delta' => 1])->assertNotFound();
            }
        }

        // the op page carries only the viewer's own timers; /hacks lists only theirs
        $this->actingAs($agent)->get("/ops/{$op->public_id}")->assertInertia(fn (Assert $p) => $p
            ->has('hackTimers', 1)->where('hackTimers.0.id', $mine->id)->etc());
        $this->actingAs($agent)->get('/hacks')->assertInertia(fn (Assert $p) => $p
            ->component('Hacks', false)->has('timers', 1)->where('timers.0.op.name', 'Op')->etc());
    }

    public function test_alert_at_tracks_when_the_current_wait_runs_out(): void
    {
        $agent = $this->mkUser(['callsign' => 'Solo', 'faction' => 'RES']);
        $timer = HackTimer::create(['user_id' => $agent->id, 'label' => 'T']);
        $this->assertNull($timer->fresh()->alert_at); // idle

        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->actingAs($agent)->post("/hacks/{$timer->id}/hack")->assertRedirect();
        $this->assertSame('2026-11-03 10:05:00', $timer->fresh()->alert_at->format('Y-m-d H:i:s')); // 300 s cooldown

        $this->actingAs($agent)->put("/hacks/{$timer->id}", ['friendly' => true])->assertRedirect();
        $this->assertSame('2026-11-03 10:03:00', $timer->fresh()->alert_at->format('Y-m-d H:i:s')); // 180 s on a friendly portal

        foreach (range(1, 3) as $_) {
            $this->actingAs($agent)->post("/hacks/{$timer->id}/hack")->assertRedirect();
        }
        $this->assertSame('2026-11-03 14:00:00', $timer->fresh()->alert_at->format('Y-m-d H:i:s')); // burnt out → window end

        $this->actingAs($agent)->post("/hacks/{$timer->id}/undo")->assertRedirect();
        $this->assertSame('2026-11-03 10:03:00', $timer->fresh()->alert_at->format('Y-m-d H:i:s')); // back to a cooldown

        Carbon::setTestNow('2026-11-03 10:03:01'); // that cooldown just ran out, alert not sent yet: a key tap can't drop it
        $this->actingAs($agent)->put("/hacks/{$timer->id}/keys", ['delta' => 1])->assertRedirect();
        $this->assertSame('2026-11-03 10:03:00', $timer->fresh()->alert_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-11-03 10:30:00'); // long after: changing the clock's inputs doesn't schedule a stale alert
        $this->actingAs($agent)->put("/hacks/{$timer->id}", ['friendly' => false])->assertRedirect();
        $this->assertNull($timer->fresh()->alert_at);
        Carbon::setTestNow();
    }

    public function test_alert_command_claims_due_timers_only_and_leaves_the_idle_clock_alone(): void
    {
        $agent = $this->mkUser(['callsign' => 'Solo', 'faction' => 'RES']);
        Carbon::setTestNow('2026-11-03 10:00:00');
        $due = HackTimer::create(['user_id' => $agent->id, 'label' => 'Due', 'hacks' => [now()->toIso8601String()]]);
        $later = HackTimer::create(['user_id' => $agent->id, 'label' => 'Later', 'hacks' => [now()->addMinutes(4)->toIso8601String()]]);
        HackTimer::whereKey([$due->id, $later->id])->update(['updated_at' => now()->subDay()]);

        Carbon::setTestNow('2026-11-03 10:05:01'); // Due's 5-min cooldown is over; Later's isn't
        $this->artisan('toady:hack-alerts', ['--seconds' => 0])->assertSuccessful();

        $this->assertNull($due->fresh()->alert_at);
        $this->assertSame('2026-11-03 10:09:00', $later->fresh()->alert_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 10:00:00', $due->fresh()->updated_at->format('Y-m-d H:i:s')); // not "touched" → prune clock intact
        Carbon::setTestNow();
    }

    public function test_alert_payload_names_the_portal_and_links_back(): void
    {
        [, $agent, $op, $wp] = $this->scene();
        $timer = $this->opTimer($agent, $op, $wp);
        $timer->update(['hacks' => [now()->toIso8601String()]]);

        $p = $timer->fresh()->alertPayload();
        $this->assertSame(['⚡ Lighthouse — ready', 'Cooldown’s over: hack away.', "/ops/{$op->public_id}?view=hacks", "hack-{$timer->id}"],
            [$p['title'], $p['body'], $p['url'], $p['tag']]);

        $timer->update(['hacks' => [now()->subHours(5)->toIso8601String()]]); // window long closed → burnout's over
        $this->assertSame('Burnout’s over: a fresh set of hacks.', $timer->fresh()->alertPayload()['body']);

        // the alerts / buzz switches (missing = on) that the command checks before pushing
        $this->assertTrue(Notifier::allows($agent, 'hacks') && Notifier::allows($agent, 'hacks_buzz'));
        $agent->update(['notify_prefs' => ['hacks_buzz' => false]]);
        $this->assertFalse(Notifier::allows($agent->fresh(), 'hacks_buzz'));

        $solo = HackTimer::create(['user_id' => $agent->id, 'label' => 'Timer 1']);
        $this->assertSame('/hacks', $solo->fresh()->alertPayload()['url']);
    }

    /** An agent with one push device and a timer whose 5-minute cooldown came due at 10:05:00. */
    private function dueTimer(array $prefs = []): HackTimer
    {
        $agent = $this->mkUser(['callsign' => 'Solo', 'faction' => 'RES', 'notify_prefs' => $prefs ?: null]);
        PushSubscription::create(['user_id' => $agent->id, 'endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/phone'] + $this->pushKeys());
        Carbon::setTestNow('2026-11-03 10:00:00');

        return HackTimer::create(['user_id' => $agent->id, 'label' => 'Fountain', 'hacks' => [now()->toIso8601String()]]);
    }

    public function test_the_watcher_pushes_a_due_alert_once_at_high_urgency_without_a_feed_row(): void
    {
        $this->fakePushService([new Response(201)]);
        $timer = $this->dueTimer();

        Carbon::setTestNow('2026-11-03 10:05:01');
        $this->artisan('toady:hack-alerts', ['--seconds' => 0])->assertSuccessful();
        $this->artisan('toady:hack-alerts', ['--seconds' => 0])->assertSuccessful(); // an overlapping run finds nothing left to send

        $this->assertCount(1, $this->pushRequests);
        $request = $this->pushRequests[0]['request'];
        $this->assertSame(['high', '300'], [$request->getHeaderLine('Urgency'), $request->getHeaderLine('TTL')]);
        $this->assertNull($timer->fresh()->alert_at);
        $this->assertSame(0, Notification::count()); // a moment-only alert — the feed isn't spammed with every cooldown
        Carbon::setTestNow();
    }

    public function test_an_alert_found_long_past_due_is_claimed_but_not_sent(): void
    {
        $this->fakePushService([new Response(201)]);
        $timer = $this->dueTimer();

        Carbon::setTestNow('2026-11-03 10:08:00'); // 3 minutes late — the watcher was down
        $this->artisan('toady:hack-alerts', ['--seconds' => 0])->assertSuccessful();

        $this->assertCount(0, $this->pushRequests);
        $this->assertNull($timer->fresh()->alert_at);
        Carbon::setTestNow();
    }

    public function test_muted_hack_alerts_are_claimed_but_not_sent(): void
    {
        $this->fakePushService([new Response(201)]);
        $timer = $this->dueTimer(['hacks' => false]);

        Carbon::setTestNow('2026-11-03 10:05:01');
        $this->artisan('toady:hack-alerts', ['--seconds' => 0])->assertSuccessful();

        $this->assertCount(0, $this->pushRequests);
        $this->assertNull($timer->fresh()->alert_at);
        Carbon::setTestNow();
    }

    public function test_idle_timers_are_pruned_after_fourteen_days(): void
    {
        $agent = $this->mkUser(['callsign' => 'Solo', 'faction' => 'RES']);
        $old = HackTimer::create(['user_id' => $agent->id, 'label' => 'Old']);
        $fresh = HackTimer::create(['user_id' => $agent->id, 'label' => 'Fresh']);
        HackTimer::whereKey($old->id)->update(['updated_at' => now()->subDays(15)]);
        HackTimer::whereKey($fresh->id)->update(['updated_at' => now()->subDays(13)]);

        $this->artisan('model:prune', ['--model' => [HackTimer::class]])->assertSuccessful();
        $this->assertSame(['Fresh'], HackTimer::pluck('label')->all());
    }
}
