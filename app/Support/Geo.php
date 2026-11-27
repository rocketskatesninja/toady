<?php

namespace App\Support;

/** Earth-surface distance — the one great-circle formula for routing fallbacks and the AI's travel tools. */
class Geo
{
    /** Great-circle (haversine) distance between two points, in metres. */
    public static function meters(float $aLat, float $aLng, float $bLat, float $bLng): float
    {
        $dLat = deg2rad($bLat - $aLat);
        $dLng = deg2rad($bLng - $aLng);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($aLat)) * cos(deg2rad($bLat)) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * asin(min(1.0, sqrt($h)));
    }
}
