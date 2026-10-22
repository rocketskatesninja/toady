<?php

namespace App\Support;

use App\Models\MasterPortal;
use App\Models\PortalContribution;
use App\Models\User;

/**
 * Records an operator naming a PLACED portal into the shared catalog, then recomputes consensus.
 * Called (best-effort, after the response) from OpWaypointController when an operator supplies a name.
 * Silently no-ops for blank titles, unplaced waypoints, ineligible users, or over-cap floods — it must
 * never slow or break the op action.
 */
class CatalogContributor
{
    /** Max NEW contributions one user may make per rolling hour (renames of an existing vote don't count). */
    public const HOURLY_CAP = 60;

    /**
     * $wp = ['title' => string, 'lat' => float, 'lng' => float, 'guid' => ?string].
     * Returns the resolved catalog portal (so the caller can link the waypoint to it), or null if skipped.
     */
    public static function contribute(User $user, array $wp): ?MasterPortal
    {
        $title = trim((string) ($wp['title'] ?? ''));
        $lat = $wp['lat'] ?? null;
        $lng = $wp['lng'] ?? null;
        $guid = trim((string) ($wp['guid'] ?? '')) ?: null;

        if ($title === '' || $lat === null || $lng === null) {
            return null; // only placed, named portals contribute
        }
        if (! $user->canContributeCatalog() || self::overCap($user)) {
            return null;
        }

        $portal = self::resolvePortal($guid, (float) $lat, (float) $lng, $user);
        $portal->contributions()->updateOrCreate(['user_id' => $user->id], ['title' => $title]);
        $portal->recomputeConsensus(); // reads the contributions fresh from the DB — no refresh() of the portal needed

        return $portal;
    }

    private static function overCap(User $user): bool
    {
        return PortalContribution::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHour())->count() >= self::HOURLY_CAP;
    }

    /** The canonical portal for this name: by GUID (authoritative), else nearest visible catalog entry, else new. */
    private static function resolvePortal(?string $guid, float $lat, float $lng, User $user): MasterPortal
    {
        if ($guid && ($p = MasterPortal::where('guid', $guid)->first())) {
            return $p;
        }
        if ($p = MasterPortal::nearestTo($lat, $lng)->first()) {
            return $p;
        }

        return MasterPortal::create([
            'guid' => $guid ?: 'contrib:'.bin2hex(random_bytes(8)),
            'lat' => $lat, 'lng' => $lng,
            'status' => MasterPortal::UNVERIFIED,
            'source' => 'contrib',
            'created_by' => $user->id,
            'first_seen' => now()->toIso8601String(),
            'last_seen' => now()->toIso8601String(),
        ]);
    }
}
