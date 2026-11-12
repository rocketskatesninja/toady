<?php

namespace Tests\Unit;

use App\Support\Fielding;
use PHPUnit\Framework\TestCase;

class FieldingTest extends TestCase
{
    private function wp(int $id, float $lat, float $lng): array
    {
        return ['id' => $id, 'lat' => $lat, 'lng' => $lng];
    }

    public function test_single_spine_is_base_link_plus_the_spine_to_both_anchors(): void
    {
        $plan = Fielding::planFan(
            [$this->wp(1, 31.20, -81.50), $this->wp(2, 31.21, -81.46)],
            [$this->wp(10, 31.19, -81.48)],
        );

        $this->assertSame([
            ['origin' => 1, 'target' => 2],   // base A1 -> A2
            ['origin' => 10, 'target' => 1],  // spine -> anchor 1
            ['origin' => 10, 'target' => 2],  // spine -> anchor 2 (no link-back — it's the only spine)
        ], $plan);
    }

    public function test_spines_are_ordered_innermost_first_by_geometry_not_input_order(): void
    {
        $a1 = $this->wp(1, 31.20, -81.49);
        $a2 = $this->wp(2, 31.21, -81.47);   // midpoint ≈ (31.205, -81.48)
        $far = $this->wp(20, 31.10, -81.48); // far from the midpoint
        $near = $this->wp(10, 31.19, -81.48); // near the midpoint

        // both spines are due south of the midpoint, so they tie on bearing and fall back to the id tiebreak
        // (10 before 20) — the point here is just that geometry, not input order, decides
        $plan = Fielding::planFan([$a1, $a2], [$far, $near]);

        $this->assertSame([
            ['origin' => 1, 'target' => 2],
            ['origin' => 10, 'target' => 1],   // nearest spine first
            ['origin' => 10, 'target' => 2],
            ['origin' => 20, 'target' => 1],   // farther spine...
            ['origin' => 20, 'target' => 2],
            ['origin' => 20, 'target' => 10],  // ...links back to the nearer one
        ], $plan);
    }

    public function test_each_spine_after_the_first_links_back_to_the_previous(): void
    {
        $plan = Fielding::planFan(
            [$this->wp(1, 31.30, -81.50), $this->wp(2, 31.30, -81.40)], // midpoint (31.30, -81.45)
            [$this->wp(10, 31.29, -81.45), $this->wp(11, 31.25, -81.45), $this->wp(12, 31.20, -81.45)],
        );

        $this->assertSame([
            ['origin' => 1, 'target' => 2],
            ['origin' => 10, 'target' => 1],
            ['origin' => 10, 'target' => 2],
            ['origin' => 11, 'target' => 1],
            ['origin' => 11, 'target' => 2],
            ['origin' => 11, 'target' => 10],
            ['origin' => 12, 'target' => 1],
            ['origin' => 12, 'target' => 2],
            ['origin' => 12, 'target' => 11],
        ], $plan);
    }

    public function test_two_anchor_fan_sweeps_by_angle_not_distance(): void
    {
        // A fan opening west: SW(10) and NW(12) are the sweep ends; W(11) is nearest the anchors but sits
        // ANGULARLY in the middle of the sweep. The old distance-from-midpoint ordering put the nearest spine
        // (W) first, which crossed the back-links; the angular sweep must keep W between its two neighbours.
        $a1 = $this->wp(1, 31.20, -81.48);
        $a2 = $this->wp(2, 31.21, -81.48);   // midpoint (31.205, -81.48)
        $sw = $this->wp(10, 31.19, -81.52);  // far
        $w = $this->wp(11, 31.205, -81.51);  // nearest the midpoint, mid-sweep
        $nw = $this->wp(12, 31.22, -81.52);  // far

        $order = array_column(Fielding::fanOrder([$a1, $a2], [$w, $sw, $nw]), 'id'); // passed out of sweep order

        $this->assertSame(11, $order[1]);    // near spine sweeps in the MIDDLE — distance order would lead with it
        $ends = [$order[0], $order[2]];
        sort($ends);
        $this->assertSame([10, 12], $ends);  // the two far spines are the sweep ends
    }

    public function test_no_self_links_and_two_anchors_required(): void
    {
        $plan = Fielding::planFan(
            [$this->wp(1, 31.20, -81.50), $this->wp(2, 31.21, -81.46)],
            [$this->wp(10, 31.19, -81.48), $this->wp(11, 31.17, -81.48)],
        );
        foreach ($plan as $link) {
            $this->assertNotSame($link['origin'], $link['target']);
        }

        $this->assertSame([], Fielding::planFan([$this->wp(1, 31.2, -81.5)], [$this->wp(10, 31.19, -81.48)]));
    }

    public function test_single_anchor_fan_sweeps_by_bearing_and_closes_each_field(): void
    {
        // anchor with three spines fanned W → S → E around it (passed out of angular order)
        $anchor = $this->wp(1, 31.20, -81.48);
        $west = $this->wp(10, 31.19, -81.50);  // bearing ≈ -150°
        $south = $this->wp(11, 31.18, -81.48);  // bearing ≈ -90°
        $east = $this->wp(12, 31.19, -81.46);  // bearing ≈ -30°

        $plan = Fielding::planSingleFan($anchor, [$east, $west, $south]);

        $this->assertSame([
            ['origin' => 1, 'target' => 10],   // anchor → westmost spine
            ['origin' => 1, 'target' => 11],   // anchor → next spine...
            ['origin' => 10, 'target' => 11],  // ...close the field to its neighbour
            ['origin' => 1, 'target' => 12],
            ['origin' => 11, 'target' => 12],
        ], $plan);
    }
}
