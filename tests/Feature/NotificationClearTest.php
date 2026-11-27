<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Op;
use App\Support\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationClearTest extends TestCase
{
    use RefreshDatabase;

    public function test_clear_removes_the_viewers_notifications_op_scoped_then_all(): void
    {
        $user = $this->mkUser(['callsign' => 'N'.substr(uniqid(), -4), 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $user->id, 'name' => 'O', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'N'.uniqid()]);
        $other = Op::create(['owner_id' => $user->id, 'name' => 'O2', 'type' => 'any_order', 'status' => 'planning', 'join_token' => 'M'.uniqid()]);
        Notification::create(['user_id' => $user->id, 'op_id' => $op->id, 'type' => 'done', 'title' => 'a']);
        Notification::create(['user_id' => $user->id, 'op_id' => $op->id, 'type' => 'done', 'title' => 'b']);
        Notification::create(['user_id' => $user->id, 'op_id' => $other->id, 'type' => 'done', 'title' => 'c']);

        // op-scoped clear removes only this op's notifications
        $this->actingAs($user)->post('/notifications/clear', ['op' => $op->public_id])->assertNoContent();
        $this->assertSame(0, Notification::where('user_id', $user->id)->where('op_id', $op->id)->count());
        $this->assertSame(1, Notification::where('user_id', $user->id)->count());

        // unscoped clear removes the rest
        $this->actingAs($user)->post('/notifications/clear')->assertNoContent();
        $this->assertSame(0, Notification::where('user_id', $user->id)->count());
    }

    public function test_clear_leaves_other_users_notifications_alone(): void
    {
        $me = $this->mkUser(['callsign' => 'Me'.substr(uniqid(), -4), 'faction' => 'ENL']);
        $them = $this->mkUser(['callsign' => 'Th'.substr(uniqid(), -4), 'faction' => 'RES']);
        Notification::create(['user_id' => $them->id, 'type' => 'done', 'title' => 'theirs']);

        $this->actingAs($me)->post('/notifications/clear')->assertNoContent();
        $this->assertSame(1, Notification::where('user_id', $them->id)->count());
    }

    public function test_an_unknown_or_closed_op_matches_nothing_never_everything(): void
    {
        $user = $this->mkUser(['callsign' => 'Keeper', 'faction' => 'ENL']);
        Notifier::send($user, 'report', 'Kept', 'x');

        // the page of a closed op still sends its (now unknown) public id
        $this->actingAs($user)->post('/notifications/clear', ['op' => 'GONE123456'])->assertNoContent();
        $this->actingAs($user)->post('/notifications/read-all', ['op' => 'GONE123456']);
        $this->actingAs($user)->getJson('/notifications/feed?op=GONE123456')->assertJson(['unread' => 1, 'items' => []]);

        $this->assertSame(1, Notification::where('user_id', $user->id)->whereNull('read_at')->count());
    }
}
