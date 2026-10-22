<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentionNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_mention_notifies_the_named_agent_and_links_to_chat(): void
    {
        $sender = $this->mkUser(['callsign' => 'Vector', 'faction' => 'ENL']);
        $mentioned = $this->mkUser(['callsign' => 'Relay', 'faction' => 'ENL']);
        $bystander = $this->mkUser(['callsign' => 'Shade', 'faction' => 'RES']);
        $op = Op::create(['owner_id' => $sender->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'C'.uniqid()]);
        foreach ([$sender, $mentioned, $bystander] as $u) {
            $op->participants()->create(['user_id' => $u->id, 'role' => $u->is($sender) ? 'operative' : 'agent']);
        }

        $this->actingAs($sender)->postJson("/ops/{$op->public_id}/chat", ['body' => 'hey @Relay can you grab keys?'])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $mentioned->id, 'type' => 'mention', 'op_id' => $op->id, 'url' => "/ops/{$op->public_id}?view=dms&tab=op",
        ]);
        // only the mentioned agent is notified — not the bystander, not the sender
        $this->assertSame(1, Notification::where('type', 'mention')->count());
    }

    public function test_mentioning_yourself_does_not_notify(): void
    {
        $sender = $this->mkUser(['callsign' => 'Solo', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $sender->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'D'.uniqid()]);
        $op->participants()->create(['user_id' => $sender->id, 'role' => 'operative']);

        $this->actingAs($sender)->postJson("/ops/{$op->public_id}/chat", ['body' => 'note to @Solo: remember the codes'])->assertOk();
        $this->assertSame(0, Notification::where('type', 'mention')->count());
    }

    public function test_operative_at_all_broadcasts_to_everyone(): void
    {
        $lead = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $a = $this->mkUser(['callsign' => 'Aaa', 'faction' => 'ENL']);
        $b = $this->mkUser(['callsign' => 'Bbb', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $lead->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'E'.uniqid()]);
        $op->participants()->create(['user_id' => $lead->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $a->id, 'role' => 'agent']);
        $op->participants()->create(['user_id' => $b->id, 'role' => 'agent']);

        $this->actingAs($lead)->postJson("/ops/{$op->public_id}/chat", ['body' => '@all move to the anchor now'])->assertOk();

        // every other participant is notified; the sender is not
        $this->assertDatabaseHas('notifications', ['user_id' => $a->id, 'type' => 'mention', 'op_id' => $op->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $b->id, 'type' => 'mention', 'op_id' => $op->id]);
        $this->assertSame(2, Notification::where('type', 'mention')->count());
    }

    public function test_agents_cannot_broadcast_with_at_all(): void
    {
        $lead = $this->mkUser(['callsign' => 'Lead', 'faction' => 'ENL']);
        $a = $this->mkUser(['callsign' => 'Aaa', 'faction' => 'ENL']);
        $b = $this->mkUser(['callsign' => 'Bbb', 'faction' => 'ENL']);
        $op = Op::create(['owner_id' => $lead->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'F'.uniqid()]);
        $op->participants()->create(['user_id' => $lead->id, 'role' => 'operative']);
        $op->participants()->create(['user_id' => $a->id, 'role' => 'agent']);
        $op->participants()->create(['user_id' => $b->id, 'role' => 'agent']);

        $this->actingAs($a)->postJson("/ops/{$op->public_id}/chat", ['body' => '@all hey everyone'])->assertOk();

        // an agent's @all is not a broadcast (and no one is named "all") → nobody is notified
        $this->assertSame(0, Notification::where('type', 'mention')->count());
    }
}
