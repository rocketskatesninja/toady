<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CycleSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_saves_the_cycle_timing_and_it_shares_globally(): void
    {
        $admin = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->put('/admin/cycle', [
            'anchor' => '2026-07-08T12:00:00Z',
            'interval_hours' => 5,
            'checkpoints_per_cycle' => 36,
            'label' => '2026.26',
        ])->assertRedirect();

        $cfg = Setting::get('cycle');
        $this->assertSame(5.0, (float) $cfg['interval_hours']);
        $this->assertSame(36, $cfg['checkpoints_per_cycle']);
        $this->assertSame(2026, $cfg['year']);
        $this->assertSame(26, $cfg['number']);
        $this->assertNotNull($cfg['anchor']);
    }

    public function test_cycle_update_validates(): void
    {
        $admin = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->put('/admin/cycle', [
            'anchor' => 'not-a-date',
            'interval_hours' => 0,
            'checkpoints_per_cycle' => 0,
            'label' => '2026',  // missing the .NN → regex fails
        ])->assertSessionHasErrors(['anchor', 'interval_hours', 'checkpoints_per_cycle', 'label']);

        $this->assertNull(Setting::get('cycle'));
    }

    public function test_non_admin_cannot_set_cycle_timing(): void
    {
        $user = $this->mkUser(['callsign' => 'Grunt', 'faction' => 'ENL']);

        $this->actingAs($user)->get('/admin/cycle')->assertForbidden();
        $this->actingAs($user)->put('/admin/cycle', [
            'anchor' => '2026-07-12T05:00:00Z', 'interval_hours' => 5, 'checkpoints_per_cycle' => 35,
        ])->assertForbidden();
        $this->assertNull(Setting::get('cycle'));
    }

    public function test_admin_tunes_the_mu_density(): void
    {
        $admin = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->put('/admin/cycle/mu', ['density' => 369])->assertRedirect();

        $this->assertSame(369.0, (float) Setting::get('mu_density'));
    }

    public function test_mu_density_validates(): void
    {
        $admin = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->put('/admin/cycle/mu', ['density' => 0])->assertSessionHasErrors('density');
        $this->actingAs($admin)->put('/admin/cycle/mu', ['density' => 'nope'])->assertSessionHasErrors('density');
        $this->assertNull(Setting::get('mu_density'));
    }

    public function test_non_admin_cannot_tune_mu_density(): void
    {
        $user = $this->mkUser(['callsign' => 'Grunt', 'faction' => 'ENL']);

        $this->actingAs($user)->put('/admin/cycle/mu', ['density' => 500])->assertForbidden();
        $this->assertNull(Setting::get('mu_density'));
    }
}
