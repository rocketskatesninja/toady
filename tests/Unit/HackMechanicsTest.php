<?php

namespace Tests\Unit;

use App\Support\Mechanics;
use PHPUnit\Framework\TestCase;

/** Hack-timer math, checked against the published examples (ingress.wiki.gg Heat Sink / Multi-hack, fevgames). */
class HackMechanicsTest extends TestCase
{
    public function test_base_cooldown_depends_on_portal_alignment(): void
    {
        $this->assertSame(180, Mechanics::cooldownSeconds(true, []));
        $this->assertSame(300, Mechanics::cooldownSeconds(false, []));
    }

    public function test_heat_sinks_stack_rarest_full_rest_half_multiplied(): void
    {
        // the wiki's worked example: 1 VR + 2 R + 1 C on a 300 s portal = 45.56 s → 46 (never early)
        $this->assertSame(46, Mechanics::cooldownSeconds(false, ['common', 'rare', 'very_rare', 'rare']));
        // one of each alone
        $this->assertSame(240, Mechanics::cooldownSeconds(false, ['common']));
        $this->assertSame(150, Mechanics::cooldownSeconds(false, ['rare']));
        $this->assertSame(90, Mechanics::cooldownSeconds(false, ['very_rare']));
        // 4 VR on a friendly portal: 180 × 0.30 × 0.65³ = 14.83 → 15
        $this->assertSame(15, Mechanics::cooldownSeconds(true, array_fill(0, 4, 'very_rare')));
    }

    public function test_multi_hacks_stack_rarest_full_rest_half(): void
    {
        $this->assertSame(4, Mechanics::hackLimit([]));
        // fevgames chart rows
        $this->assertSame(8, Mechanics::hackLimit(['common']));
        $this->assertSame(10, Mechanics::hackLimit(['common', 'common']));
        $this->assertSame(14, Mechanics::hackLimit(['common', 'rare']));
        $this->assertSame(16, Mechanics::hackLimit(['rare', 'rare']));
        $this->assertSame(22, Mechanics::hackLimit(['very_rare', 'very_rare']));
        $this->assertSame(34, Mechanics::hackLimit(array_fill(0, 4, 'very_rare')));
    }
}
