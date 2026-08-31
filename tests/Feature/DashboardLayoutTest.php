<?php

namespace Tests\Feature;

use App\Dashboard\OpWidgets;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_saves_and_returns_custom_layout_per_op(): void
    {
        $u = $this->mkUser(['google_id' => 'd', 'callsign' => 'D', 'faction' => 'ENL']);
        $layout = [
            ['i' => 'map', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
            ['i' => 'dms', 'x' => 0, 'y' => 6, 'w' => 6, 'h' => 5],
        ];

        $this->actingAs($u)->put('/dashboard/layout', ['op_id' => 'AB12CD34EF', 'mode' => 'desktop', 'layout' => $layout])->assertNoContent();

        $u->refresh();
        $layouts = OpWidgets::layoutsFor($u, 'AB12CD34EF');
        $this->assertCount(2, $layouts['desktop']);
        $this->assertSame('dms', $layouts['desktop'][1]['i']);
        $this->assertNotEmpty($layouts['mobile']); // unsaved mode → defaults

        // a DIFFERENT op keeps its own layout (defaults — the custom 2-widget one above doesn't bleed across)
        $other = OpWidgets::layoutsFor($u, 99);
        $this->assertGreaterThan(2, count($other['desktop'])); // defaults, not the saved 2-widget layout
    }

    public function test_layout_save_requires_an_op_id(): void
    {
        $u = $this->mkUser(['google_id' => 'dx', 'callsign' => 'D', 'faction' => 'ENL']);
        $this->actingAs($u)->putJson('/dashboard/layout', ['mode' => 'desktop', 'layout' => []])->assertStatus(422);
    }

    public function test_activity_widget_is_operator_only(): void
    {
        $this->assertArrayHasKey('activity', OpWidgets::meta(true));
        $this->assertArrayNotHasKey('activity', OpWidgets::meta(false)); // hidden from agents

        // the operator-only widget is stripped from a non-operative's layout, kept for an operative
        $u = $this->mkUser(['google_id' => 'd3', 'callsign' => 'D', 'faction' => 'ENL']);
        $u->update(['dashboard_layout' => ['ops' => ['1' => ['desktop' => [
            ['i' => 'activity', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 7],
            ['i' => 'map', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 8],
        ]]]]]);

        $this->assertCount(1, OpWidgets::layoutsFor($u->fresh(), 1, false)['desktop']); // agent: activity stripped
        $this->assertCount(2, OpWidgets::layoutsFor($u->fresh(), 1, true)['desktop']);  // operative: kept
    }

    public function test_unknown_widget_keys_are_sanitized_out(): void
    {
        $u = $this->mkUser(['google_id' => 'd2', 'callsign' => 'D', 'faction' => 'ENL']);
        $u->update(['dashboard_layout' => ['ops' => ['1' => ['desktop' => [
            ['i' => 'bogus', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 4],
            ['i' => 'map', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 8],
        ]]]]]);

        $layouts = OpWidgets::layoutsFor($u->fresh(), 1);
        $this->assertCount(1, $layouts['desktop']);
        $this->assertSame('map', $layouts['desktop'][0]['i']);
    }

    public function test_retired_directives_and_keys_widgets_fold_into_plan(): void
    {
        $u = $this->mkUser(['google_id' => 'd4', 'callsign' => 'D', 'faction' => 'ENL']);
        // a layout saved before the Recon + Directives panels were merged into Plan
        $u->update(['dashboard_layout' => ['ops' => ['AB12CD34EF' => ['desktop' => [
            ['i' => 'directives', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 9],
            ['i' => 'keys', 'x' => 5, 'y' => 0, 'w' => 4, 'h' => 6],
            ['i' => 'map', 'x' => 0, 'y' => 9, 'w' => 8, 'h' => 8],
        ]]]]]);

        $desktop = OpWidgets::layoutsFor($u->fresh(), 'AB12CD34EF')['desktop'];
        // both retired keys collapse to a single (deduped) 'plan', keeping the first one's slot; map survives
        $this->assertSame(['plan', 'map'], array_column($desktop, 'i'));
        $this->assertSame(5, $desktop[0]['w']); // plan inherits the old directives slot's width
    }
}
