<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `model:prune` applies each model's retention window so durable tables (notifications, audit logs)
 * can't grow forever. Recent rows survive; stale rows are dropped.
 */
class PruneRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_prune_drops_stale_notifications_and_keeps_recent(): void
    {
        $u = $this->mkUser(['google_id' => 'n', 'callsign' => 'N', 'faction' => 'ENL']);

        $old = Notification::create(['user_id' => $u->id, 'type' => 'x', 'title' => 'old']);
        $old->forceFill(['created_at' => now()->subDays(75)])->saveQuietly();
        $recent = Notification::create(['user_id' => $u->id, 'type' => 'x', 'title' => 'recent']);

        $this->artisan('model:prune', ['--model' => [Notification::class]])->assertOk();

        $this->assertDatabaseMissing('notifications', ['id' => $old->id]);
        $this->assertDatabaseHas('notifications', ['id' => $recent->id]);
    }

    public function test_model_prune_drops_year_old_audit_logs(): void
    {
        $old = AuditLog::create(['actor_label' => 'admin', 'action' => 'suspend', 'summary' => 'old']);
        $old->forceFill(['created_at' => now()->subMonths(13)])->saveQuietly();
        $recent = AuditLog::create(['actor_label' => 'admin', 'action' => 'suspend', 'summary' => 'recent']);

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
    }
}
