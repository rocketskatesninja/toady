<?php

namespace Tests\Feature;

use App\Jobs\SendCampaignEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $u = $this->mkUser(['callsign' => 'Owner', 'faction' => 'ENL', 'email' => 'owner@example.com']);
        $u->is_owner = true;
        $u->is_admin = true;
        $u->save();

        return $u;
    }

    public function test_owner_queues_a_broadcast_and_skips_opt_outs(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $a = $this->mkUser(['callsign' => 'A', 'faction' => 'ENL', 'email' => 'a@example.com']);
        $b = $this->mkUser(['callsign' => 'B', 'faction' => 'ENL', 'email' => 'b@example.com', 'email_opt_out' => true]);

        $this->actingAs($owner)->post('/admin/users/email', [
            'subject' => 'Hello', 'header' => 'News', 'body' => '<p>hi</p>', 'signature' => '— Owner', 'format' => 'html', 'recipients' => [$a->id, $b->id],
        ])->assertRedirect();

        Queue::assertPushed(SendCampaignEmail::class, 1); // only A — B opted out
        $this->assertDatabaseHas('mail_campaigns', ['subject' => 'Hello', 'recipient_count' => 1, 'created_by' => $owner->id]);
        // header + signature are remembered on the sender's account
        $this->assertSame('News', $owner->fresh()->mail_header);
        $this->assertSame('— Owner', $owner->fresh()->mail_signature);
    }

    public function test_non_owner_admin_cannot_broadcast(): void
    {
        $admin = $this->mkUser(['callsign' => 'Adm', 'faction' => 'ENL', 'email' => 'adm@example.com']);
        $admin->is_admin = true;
        $admin->save();

        $this->actingAs($admin)->post('/admin/users/email', ['subject' => 'x', 'body' => 'y', 'format' => 'html'])->assertForbidden();
    }

    public function test_bulk_suspend_then_delete(): void
    {
        $admin = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $admin->is_admin = true;
        $admin->save();
        $a = $this->mkUser(['callsign' => 'A', 'faction' => 'ENL']);
        $b = $this->mkUser(['callsign' => 'B', 'faction' => 'ENL']);

        $this->actingAs($admin)->post('/admin/users/bulk', ['action' => 'suspend', 'ids' => [$a->id, $b->id]])->assertRedirect();
        $this->assertNotNull($a->fresh()->suspended_at);
        $this->assertNotNull($b->fresh()->suspended_at);

        $this->actingAs($admin)->post('/admin/users/bulk', ['action' => 'delete', 'ids' => [$a->id]])->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $a->id]);
    }

    public function test_signed_unsubscribe_opts_the_user_out(): void
    {
        $u = $this->mkUser(['callsign' => 'U', 'faction' => 'ENL', 'email' => 'u@example.com']);

        $this->get(URL::signedRoute('unsubscribe', ['user' => $u->id]))->assertOk();
        $this->assertTrue($u->fresh()->email_opt_out);
    }
}
