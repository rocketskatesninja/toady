<?php

namespace App\Models;

use App\Support\Geo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['guid', 'title', 'lat', 'lng', 'region', 'source', 'status', 'created_by', 'first_seen', 'last_seen', 'image', 'gate_pin', 'access_notes', 'parking', 'hours', 'hazards'])]
class MasterPortal extends Model
{
    // unverified: 1 contributor · verified: consensus (2+ or a trusted contributor) or seeded import
    // owner_locked: owner-set, frozen from consensus · hidden: flagged out, excluded from reads
    public const UNVERIFIED = 'unverified';

    public const VERIFIED = 'verified';

    public const OWNER_LOCKED = 'owner_locked';

    public const HIDDEN = 'hidden';

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float'];
    }

    public function hasIntel(): bool
    {
        return collect($this->only(array_keys(OpWaypoint::INTEL)))->filter()->isNotEmpty();
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(PortalContribution::class);
    }

    public function flags(): HasMany
    {
        return $this->hasMany(PortalFlag::class);
    }

    /** A frozen name (owner-locked or flagged-hidden) is never rewritten by consensus. */
    public function isFrozen(): bool
    {
        return in_array($this->status, [self::OWNER_LOCKED, self::HIDDEN], true);
    }

    /** This catalog portal's identity + intel as waypoint attributes, snapshotted so the waypoint is
     *  decoupled from later catalog edits. Shared by manual add + IITC import so they can't drift. */
    public function toWaypoint(): array
    {
        return ['title' => $this->title, 'lat' => $this->lat, 'lng' => $this->lng, 'image' => $this->image, ...$this->only(array_keys(OpWaypoint::INTEL))];
    }

    /**
     * Recompute the canonical name + status from the contributions.
     *  - The proposed title with the most DISTINCT contributors wins (tie → most recent).
     *  - verified when that title has ≥2 distinct contributors OR any trusted contributor.
     *  - A verified/seeded name is only overwritten by a DIFFERENT title that itself reaches verified,
     *    so a lone unverified challenger can't rename an established portal.
     *  - owner_locked / hidden are frozen.
     */
    public function recomputeConsensus(): void
    {
        if ($this->isFrozen()) {
            return;
        }
        $byTitle = $this->contributions()
            ->selectRaw('title, COUNT(DISTINCT user_id) as n, MAX(id) as last_id')
            ->groupBy('title')->orderByDesc('n')->orderByDesc('last_id')->get();
        if ($byTitle->isEmpty()) {
            return;
        }
        $top = $byTitle->first();
        $verified = (int) $top->n >= 2
            || $this->contributions()->where('title', $top->title)
                ->whereHas('user', fn ($q) => $q->where('trusted', true))->exists();

        if ($this->status === self::VERIFIED) {
            if ($verified && $top->title !== $this->title) {
                $this->update(['title' => $top->title]);
            }
        } else {
            $this->update(['title' => $top->title, 'status' => $verified ? self::VERIFIED : self::UNVERIFIED]);
        }
    }

    /** Distinct operators who've contributed a name (drives the "N sources" badge + the flag threshold). */
    public function contributorCount(): int
    {
        return (int) $this->contributions()->distinct('user_id')->count('user_id');
    }

    /** Hide a name the community disputes: flags ≥ contributors and ≥2. Owner-locked names are exempt
     *  (only the owner touches those). Hidden portals drop out of every auto-naming read. */
    public function recomputeFlagStatus(): void
    {
        if ($this->status === self::OWNER_LOCKED) {
            return;
        }
        $flags = $this->flags()->count();
        if ($flags >= 2 && $flags >= $this->contributorCount() && $this->status !== self::HIDDEN) {
            $this->update(['status' => self::HIDDEN]);
        }
    }

    /** Every word must appear in the name, in any order ("baptist first" finds "First Baptist Church"). */
    public function scopeSearch(Builder $q, ?string $t): Builder
    {
        foreach (array_slice(preg_split('/\s+/', trim((string) $t), -1, PREG_SPLIT_NO_EMPTY), 0, 6) as $word) {
            $q->where('title', 'like', '%'.addcslashes($word, '%_\\').'%');
        }

        return $q;
    }

    /** Nearest first, anywhere (no bounding box) — flat-earth distance with longitude scaled for the latitude. */
    public function scopeClosestTo(Builder $q, float $lat, float $lng): Builder
    {
        $k = cos(deg2rad($lat)) ** 2;

        return $q->orderByRaw('(lat - ?) * (lat - ?) + (lng - ?) * (lng - ?) * ?', [$lat, $lat, $lng, $lng, $k]);
    }

    /**
     * Bring portals in from an IITC export (the owner's upload, or the toady:catalog-seed command) — upsert by GUID.
     * IITC carries Niantic's own names, so an imported name confirms a portal; but it never overrides what was
     * decided here: an owner-locked or hidden portal keeps its name, a region already set stays, and a known name
     * or photo is never blanked. Field intel is not touched.
     *
     * @param  array<int, array{guid: string, title?: ?string, lat: float, lng: float, image?: ?string, region?: ?string, first_seen?: ?string, last_seen?: ?string}>  $rows
     * @return array{added: int, updated: int, merged: int}
     */
    public static function import(array $rows, string $source): array
    {
        $now = now();
        $stamp = $now->toIso8601String();
        $before = static::count();
        $frozen = "master_portals.status IN ('".self::OWNER_LOCKED."', '".self::HIDDEN."')";
        $n = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $values = [];
            foreach ($chunk as $r) {
                // a GUID repeated inside one statement would make the upsert fail — the last one wins
                $values[$r['guid']] = [
                    'guid' => $r['guid'],
                    'title' => ($t = trim((string) ($r['title'] ?? ''))) !== '' ? $t : null,
                    'lat' => $r['lat'], 'lng' => $r['lng'],
                    'region' => ($g = trim((string) ($r['region'] ?? ''))) !== '' ? $g : null,
                    'image' => ($r['image'] ?? null) ?: null,
                    'source' => $source,
                    'status' => self::VERIFIED,
                    'first_seen' => ($r['first_seen'] ?? null) ?: $stamp,
                    'last_seen' => ($r['last_seen'] ?? null) ?: $stamp,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            $n += count($values);
            static::upsert(array_values($values), ['guid'], [
                'title' => DB::raw("CASE WHEN {$frozen} THEN master_portals.title ELSE COALESCE(excluded.title, master_portals.title) END"),
                'status' => DB::raw("CASE WHEN master_portals.status = '".self::UNVERIFIED."' AND excluded.title IS NOT NULL THEN '".self::VERIFIED."' ELSE master_portals.status END"),
                'lat', 'lng', 'source', 'last_seen', 'updated_at',
                'region' => DB::raw('COALESCE(master_portals.region, excluded.region)'),
                'image' => DB::raw('COALESCE(excluded.image, master_portals.image)'),
            ]);
        }
        $added = static::count() - $before;
        $merged = static::absorbStandIns($rows);

        return ['added' => $added, 'updated' => $n - $added, 'merged' => $merged];
    }

    /** How close a community-added portal must be to an imported one to be taken as the same portal. */
    public const SAME_PORTAL_METERS = 15;

    /**
     * A portal someone named before the catalog had it carries a made-up id ("contrib:…"). Once an import brings
     * the real one in at the same spot, the stand-in goes: plan portals linked to it follow to the real one, and
     * any field intel it carried is kept where the real one has none. Only stand-ins are ever removed.
     *
     * @param  array<int, array{guid: string, lat: float, lng: float}>  $rows  the portals just imported
     */
    private static function absorbStandIns(array $rows): int
    {
        if (! $rows) {
            return 0;
        }
        $pad = 0.001; // ~100 m around the imported area — wide enough to catch every candidate
        $lats = array_column($rows, 'lat');
        $lngs = array_column($rows, 'lng');
        $standIns = static::where('guid', 'like', 'contrib:%')
            ->whereBetween('lat', [min($lats) - $pad, max($lats) + $pad])->whereBetween('lng', [min($lngs) - $pad, max($lngs) + $pad])->get();
        $imported = array_flip(array_column($rows, 'guid'));
        $merged = 0;

        foreach ($standIns as $standIn) {
            $real = static::whereBetween('lat', [$standIn->lat - 0.0005, $standIn->lat + 0.0005])->whereBetween('lng', [$standIn->lng - 0.0005, $standIn->lng + 0.0005])
                ->whereKeyNot($standIn->id)->closestTo($standIn->lat, $standIn->lng)->get()
                ->first(fn (self $p) => isset($imported[$p->guid]) && Geo::meters($standIn->lat, $standIn->lng, $p->lat, $p->lng) <= self::SAME_PORTAL_METERS);
            if (! $real) {
                continue;
            }
            DB::transaction(function () use ($standIn, $real) {
                $intel = array_filter($standIn->only(array_keys(OpWaypoint::INTEL)), fn ($v) => $v !== null && $v !== '');
                $real->update(array_diff_key($intel, array_filter($real->only(array_keys(OpWaypoint::INTEL)), fn ($v) => $v !== null && $v !== '')));
                OpWaypoint::where('guid', $standIn->guid)->update(['guid' => $real->guid]);
                $standIn->delete(); // its votes and flags cascade
            });
            $merged++;
        }

        return $merged;
    }

    public function scopeRegion(Builder $q, ?string $r): Builder
    {
        $r = trim((string) $r);

        return $r !== '' ? $q->where('region', $r) : $q;
    }

    /** The catalog entry for a place: by portal GUID (authoritative), else the nearest visible one, else null. */
    public static function locate(?string $guid, ?float $lat, ?float $lng): ?self
    {
        return ($guid ? static::visible()->where('guid', $guid)->first() : null)
            ?? ($lat !== null && $lng !== null ? static::nearestTo($lat, $lng)->first() : null);
    }

    /** Confirmed names (verified / owner-locked) ahead of single-source unverified ones. */
    public function scopeTrustedFirst(Builder $q): Builder
    {
        return $q->orderByRaw("CASE WHEN status = '".self::UNVERIFIED."' THEN 1 ELSE 0 END");
    }

    /** Exclude flagged-hidden portals from any read that auto-populates an op. */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('status', '!=', self::HIDDEN);
    }

    /** Catalog portals near a coordinate, nearest first — for naming map-drops / pasted Intel links.
     *  Hidden portals are dropped, and verified/owner-locked names rank ahead of unverified ones. */
    public function scopeNearestTo(Builder $q, float $lat, float $lng, float $eps = 0.0005): Builder
    {
        // squared euclidean distance without POW() (not guaranteed compiled into SQLite); the whereBetween box already narrows it
        return $q->visible()
            ->whereBetween('lat', [$lat - $eps, $lat + $eps])->whereBetween('lng', [$lng - $eps, $lng + $eps])
            ->trustedFirst()
            ->orderByRaw('(lat - ?) * (lat - ?) + (lng - ?) * (lng - ?)', [$lat, $lat, $lng, $lng]);
    }
}
