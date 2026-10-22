<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $u->is_admin = true;
        $u->save();

        return $u;
    }

    public function test_non_admin_cannot_reach_the_admin_area(): void
    {
        $this->actingAs($this->mkUser(['callsign' => 'Reg', 'faction' => 'ENL']))->get('/admin/users')->assertForbidden();
    }

    public function test_admin_sees_the_user_list(): void
    {
        $this->actingAs($this->admin())->get('/admin/users')->assertOk();
    }

    public function test_bulk_suspend_is_audited(): void
    {
        $admin = $this->admin();
        $target = $this->mkUser(['callsign' => 'Tgt', 'faction' => 'ENL']);

        $this->actingAs($admin)->post('/admin/users/bulk', ['action' => 'suspend', 'ids' => [$target->id]])->assertRedirect();
        $this->assertNotNull($target->fresh()->suspended_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'suspend', 'actor_id' => $admin->id]);
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        $u = $this->mkUser(['callsign' => 'Sus', 'faction' => 'ENL', 'email' => 'sus@x.com', 'password' => 'secret123']);
        $u->suspended_at = now();
        $u->save();

        $this->post('/login', ['email' => 'sus@x.com', 'password' => 'secret123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_bulk_never_touches_self_or_owner(): void
    {
        $admin = $this->admin();
        $target = $this->mkUser(['callsign' => 'Del', 'faction' => 'ENL']);
        $owner = $this->mkUser(['callsign' => 'Own', 'faction' => 'ENL']);
        $owner->is_owner = true;
        $owner->save();

        // a bulk delete that names a real target, the owner, and yourself → only the target goes
        $this->actingAs($admin)->post('/admin/users/bulk', ['action' => 'delete', 'ids' => [$target->id, $owner->id, $admin->id]])->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $target->id]); // deleted
        $this->assertDatabaseHas('users', ['id' => $owner->id]);      // owner untouchable
        $this->assertDatabaseHas('users', ['id' => $admin->id]);      // never yourself

        // suspending yourself is likewise a no-op
        $this->actingAs($admin)->post('/admin/users/bulk', ['action' => 'suspend', 'ids' => [$admin->id]])->assertRedirect();
        $this->assertNull($admin->fresh()->suspended_at);
    }

    public function test_suspended_session_is_signed_out_mid_session(): void
    {
        $u = $this->mkUser(['callsign' => 'Mid', 'faction' => 'ENL']);
        $this->actingAs($u);
        $u->suspended_at = now();
        $u->save();

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
