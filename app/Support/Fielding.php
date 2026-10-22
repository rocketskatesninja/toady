<?php

namespace App\Support;

/**
 * Fan-field planner. Given exactly two anchors and a set of spine portals, produce the link
 * directives for a classic 2-anchor fan in the order they must be thrown: innermost spine first,
 * fielding outward — you can't link from inside a field, so each new field has to be the outermost
 * one. Each spine links to both anchors plus the previous spine; the big outer field closes itself
 * from the last spine's anchor links, so no separate "closing" link is emitted.
 */
class Fielding
{
    /**
     * @param  list<array{id:int,lat:float,lng:float}>  $anchors  exactly 2
     * @param  list<array{id:int,lat:float,lng:float}>  $spines
     * @return list<array{origin:int,target:int}> one entry per single link, in throw order (base link first)
     */
    public static function planFan(array $anchors, array $spines): array
    {
        if (count($anchors) !== 2) {
            return [];
        }
        [$a1, $a2] = array_values($anchors);
        $spines = self::fanOrder($anchors, $spines); // innermost first

        $links = [['origin' => $a1['id'], 'target' => $a2['id']]]; // base A1 -> A2 (innermost triangle)

        $prev = null;
        foreach ($spines as $s) {
            $links[] = ['origin' => $s['id'], 'target' => $a1['id']]; // to both anchors first...
            $links[] = ['origin' => $s['id'], 'target' => $a2['id']];
            if ($prev !== null) {
                $links[] = ['origin' => $s['id'], 'target' => $prev['id']]; // ...then back to the previous spine
            }
            $prev = $s;
        }

        return $links;
    }

    /**
     * Spines in the order a 2-anchor fan is thrown/visited: swept by their ANGLE around one anchor. A fan is
     * built side by side across the sweep (you can't link from inside a field), so adjacent spines must be
     * angular neighbours or their back-links cross. The angle is measured around one anchor (not the midpoint)
     * and centred on the spine centroid: a fan opens to one side of an anchor, so this never straddles the
     * atan2 branch cut that would split a wide sweep, and viewed from an off-line anchor even a straight
     * spine-line's nested triangles subtend a monotonic angle — so this one metric orders both a wide angular
     * fan and a straight beam. Oriented innermost-first (nearest the anchor midpoint) for a stable throw order
     * regardless of anchor order; tiebreak by id. Used both to build the plan and to renumber into fielding order.
     *
     * @param  list<array{id:int,lat:float,lng:float}>  $anchors  exactly 2
     * @param  list<array{id:int,lat:float,lng:float}>  $spines
     * @return list<array{id:int,lat:float,lng:float}> spines, in sweep order
     */
    public static function fanOrder(array $anchors, array $spines): array
    {
        if (count($anchors) !== 2 || count($spines) < 2) {
            return $spines;
        }
        [$a1, $a2] = array_values($anchors);
        $mid = self::midpoint($a1, $a2);
        $cosLat = cos(deg2rad($a1['lat']));
        $vec = fn ($p) => [($p['lng'] - $a1['lng']) * $cosLat, $p['lat'] - $a1['lat']]; // from a1, lng scaled

        // reference the sweep on the direction from a1 to the spine centroid, so a wide fan can't wrap the cut
        $cx = 0.0;
        $cy = 0.0;
        foreach ($spines as $s) {
            [$x, $y] = $vec($s);
            $cx += $x;
            $cy += $y;
        }
        $ref = atan2($cy, $cx);
        $rel = function ($p) use ($vec, $ref) {
            [$x, $y] = $vec($p);
            $d = atan2($y, $x) - $ref;

            return atan2(sin($d), cos($d)); // normalised to (-π, π] around the centroid direction
        };
        usort($spines, fn ($p, $q) => ($rel($p) <=> $rel($q)) ?: ($p['id'] <=> $q['id']));

        // orient innermost-first: the spine nearest the anchor midpoint leads (stable regardless of anchor order)
        if (self::dist2($spines[count($spines) - 1], $mid) < self::dist2($spines[0], $mid)) {
            $spines = array_reverse($spines);
        }

        return $spines;
    }

    /**
     * Single-anchor fan: one anchor throws out to a sweep of portals, each closing a triangle with its
     * angular neighbour. Spines are ordered by bearing around the anchor so the fan is built side by side
     * (you can't link from inside a field) — the anchor links to each in turn, then each spine links back
     * to the previous one to close its field.
     *
     * @param  array{id:int,lat:float,lng:float}  $anchor
     * @param  list<array{id:int,lat:float,lng:float}>  $spines
     * @return list<array{origin:int,target:int}> one entry per single link, in throw order
     */
    public static function planSingleFan(array $anchor, array $spines): array
    {
        $spines = self::singleFanOrder($anchor, $spines); // swept by bearing

        $links = [];
        $prev = null;
        foreach ($spines as $s) {
            $links[] = ['origin' => $anchor['id'], 'target' => $s['id']]; // anchor throws out to the spine...
            if ($prev !== null) {
                $links[] = ['origin' => $prev['id'], 'target' => $s['id']]; // ...then close the field to its neighbour
            }
            $prev = $s;
        }

        return $links;
    }

    /**
     * Spines swept by bearing around the single anchor — the fan is built side by side (you can't link
     * from inside a field), so the throw/visit order follows the sweep. Tiebreak by id for determinism.
     *
     * @param  array{id:int,lat:float,lng:float}  $anchor
     * @param  list<array{id:int,lat:float,lng:float}>  $spines
     * @return list<array{id:int,lat:float,lng:float}> spines, in sweep order
     */
    public static function singleFanOrder(array $anchor, array $spines): array
    {
        // sweep order: bearing of each spine from the anchor (longitude scaled once for the anchor's latitude)
        $cosLat = cos(deg2rad($anchor['lat']));
        $bearing = fn ($p) => atan2($p['lat'] - $anchor['lat'], ($p['lng'] - $anchor['lng']) * $cosLat);
        usort($spines, fn ($p, $q) => ($bearing($p) <=> $bearing($q)) ?: ($p['id'] <=> $q['id']));

        return $spines;
    }

    /** @return array{lat:float,lng:float} */
    private static function midpoint(array $a, array $b): array
    {
        return ['lat' => ($a['lat'] + $b['lat']) / 2, 'lng' => ($a['lng'] + $b['lng']) / 2];
    }

    /** Equirectangular squared distance — enough to ORDER nearby points, avoids the sqrt/haversine cost. */
    private static function dist2(array $p, array $q): float
    {
        $x = ($p['lng'] - $q['lng']) * cos(deg2rad(($p['lat'] + $q['lat']) / 2));
        $y = $p['lat'] - $q['lat'];

        return $x * $x + $y * $y;
    }
}
