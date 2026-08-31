<?php

namespace App\Support;

/**
 * Verified Ingress mechanics — single source of truth (HANDOFF §3, source-verified
 * via Niantic + ingress.fandom/wiki/fevgames). Trust these over any model recall.
 */
class Mechanics
{
    /** Link range = 160 m × (avg resonator level)^4 (× link-amp, not modelled here). */
    public const LINK_RANGE_BASE_M = 160.0;

    /**
     * Solo-deployable max average resonator level. The 8 solo-deploy caps
     * (L8×1, L7×1, L6×2, L5×2, L4×2) average to 5.625 → a solo "L5" portal.
     */
    public const SOLO_MAX_AVG_RESO = 5.625;

    // Hacking / keys
    public const HACKS_BEFORE_BURNOUT = 4;
    public const BURNOUT_HOURS = 4;
    public const COOLDOWN_OWN_MIN = 3;
    public const COOLDOWN_ENEMY_MIN = 5;
    public const KEY_DROP_RATE = 0.75;

    /** Heat Sink cooldown reduction by rarity. */
    public const HEAT_SINK = ['common' => 0.20, 'rare' => 0.50, 'very_rare' => 0.70];

    /** Multi-hack extra hacks by rarity. */
    public const MULTI_HACK = ['common' => 4, 'rare' => 8, 'very_rare' => 12];

    // ---- resonators / deployment ----
    public const PORTAL_SLOTS = 8;       // a portal has 8 resonator slots; portal level = floor(avg of all 8)
    public const DEPLOY_RANGE_M = 40;    // interaction range to deploy / hack / link / recharge
    /** Max resonators of each level ONE agent may deploy on a single portal (level => count). */
    public const RESONATOR_LIMITS = [8 => 1, 7 => 1, 6 => 2, 5 => 2, 4 => 2, 3 => 4, 2 => 4, 1 => 8];

    // ---- mods (4 slots per portal, max 2 per agent) ----
    public const MOD_SLOTS = 4;
    public const MODS_PER_AGENT = 2;
    /** Portal Shield XMP-damage mitigation by rarity (%). */
    public const SHIELD_MITIGATION = ['common' => 30, 'rare' => 40, 'very_rare' => 60];

    /** AP earned per action. */
    public const AP = [
        'Deploy resonator' => 125,
        'Deploy mod' => 125,
        "Upgrade another's resonator" => 65,
        'Capture portal · 1st resonator' => 500,
        'Complete portal · 8th resonator' => 250,
        'Create link' => 313,
        'Create control field' => 1250,
        'Destroy resonator' => 75,
        'Destroy link' => 187,
        'Destroy control field' => 750,
    ];

    /** Cumulative AP for each agent level (L9+ ALSO require the badge gates below). */
    public const LEVEL_AP = [
        1 => 0, 2 => 2500, 3 => 20000, 4 => 70000, 5 => 150000, 6 => 300000, 7 => 600000, 8 => 1200000,
        9 => 2400000, 10 => 4000000, 11 => 6000000, 12 => 8400000, 13 => 12000000, 14 => 17000000, 15 => 24000000, 16 => 40000000,
    ];

    /** Extra medal gates for L9–L16, on top of the AP. */
    public const LEVEL_BADGES = [
        9 => '1 Platinum · 4 Gold',
        10 => '2 Platinum · 5 Gold',
        11 => '1 Onyx · 3 Platinum · 6 Gold',
        12 => '2 Onyx · 4 Platinum · 7 Gold',
        13 => '3 Onyx · 5 Platinum',
        14 => '4 Onyx · 6 Platinum',
        15 => '5 Onyx · 7 Platinum',
        16 => '6 Onyx · 7 Platinum',
    ];

    /** Link range in metres for a given average resonator level. */
    public static function linkRangeMeters(float $avgReso): float
    {
        return self::LINK_RANGE_BASE_M * ($avgReso ** 4);
    }

    /** Max link range a solo agent can throw (avg reso 5.625) ≈ 160 km. */
    public static function soloMaxLinkMeters(): float
    {
        return self::linkRangeMeters(self::SOLO_MAX_AVG_RESO);
    }
}
