<?php

namespace Tests\Feature;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_returns_own_items_and_unread_count(): void
    {
        $a = $this->mkUser(['callsign' => 'Aaa', 'faction' => 'ENL']);
        Notification::create(['user_id' => $a->id, 'type' => 'dm', 'title' => 'One']);
        Notification::create(['user_id' => $a->id, 'type' => 'join', 'title' => 'Two']);

        $this->actingAs($a)->getJson('/notifications/feed')
            ->assertOk()->assertJsonPath('unread', 2)->assertJsonCount(2, 'items');
    }

    public function test_marking_read_is_own_only(): void
    {
        $a = $this->mkUser(['callsign' => 'Owna', 'faction' => 'ENL']);
        $b = $this->mkUser(['callsign' => 'Othr', 'faction' => 'RES']);
        $n = Notification::create(['user_id' => $a->id, 'type' => 'dm', 'title' => 'Secret']);

        // a stranger cannot mark someone else's notification read
        $this->actingAs($b)->postJson("/notifications/{$n->id}/read")->assertForbidden();
        $this->assertNull($n->fresh()->read_at);

        // the owner can
        $this->actingAs($a)->postJson("/notifications/{$n->id}/read")->assertNoContent();
        $this->assertNotNull($n->fresh()->read_at);

        // mark-all clears the rest
        Notification::create(['user_id' => $a->id, 'type' => 'go', 'title' => 'Live']);
        $this->actingAs($a)->post('/notifications/read-all')->assertRedirect();
        $this->assertSame(0, Notification::where('user_id', $a->id)->whereNull('read_at')->count());
    }
}
