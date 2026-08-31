<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
#[Fillable(['guid','title','lat','lng','region','source','status','created_by','first_seen','last_seen','image','gate_pin','access_notes','parking','hours','hazards'])]
class MasterPortal extends Model {
    // unverified: 1 contributor · verified: consensus (2+ or a trusted contributor) or seeded import
    // owner_locked: owner-set, frozen from consensus · hidden: flagged out, excluded from reads
    public const UNVERIFIED = 'unverified';
    public const VERIFIED = 'verified';
    public const OWNER_LOCKED = 'owner_locked';
    public const HIDDEN = 'hidden';

    protected function casts(): array { return ['lat'=>'float','lng'=>'float']; }
    public function hasIntel(): bool { return (bool)($this->gate_pin||$this->access_notes||$this->parking||$this->hours||$this->hazards); }

    public function contributions(): HasMany { return $this->hasMany(PortalContribution::class); }
    public function flags(): HasMany { return $this->hasMany(PortalFlag::class); }

    /** A frozen name (owner-locked or flagged-hidden) is never rewritten by consensus. */
    public function isFrozen(): bool { return in_array($this->status, [self::OWNER_LOCKED, self::HIDDEN], true); }

    /** This catalog portal's identity + intel as waypoint attributes, snapshotted so the waypoint is
     *  decoupled from later catalog edits. Shared by manual add + IITC import so they can't drift. */
    public function toWaypoint(): array {
        return ['title'=>$this->title,'lat'=>$this->lat,'lng'=>$this->lng,'image'=>$this->image,'gate_pin'=>$this->gate_pin,'access_notes'=>$this->access_notes,'parking'=>$this->parking,'hours'=>$this->hours,'hazards'=>$this->hazards];
    }

    /**
     * Recompute the canonical name + status from the contributions.
     *  - The proposed title with the most DISTINCT contributors wins (tie → most recent).
     *  - verified when that title has ≥2 distinct contributors OR any trusted contributor.
     *  - A verified/seeded name is only overwritten by a DIFFERENT title that itself reaches verified,
     *    so a lone unverified challenger can't rename an established portal.
     *  - owner_locked / hidden are frozen.
     */
    public function recomputeConsensus(): void {
        if ($this->isFrozen()) { return; }
        $byTitle = $this->contributions()
            ->selectRaw('title, COUNT(DISTINCT user_id) as n, MAX(id) as last_id')
            ->groupBy('title')->orderByDesc('n')->orderByDesc('last_id')->get();
        if ($byTitle->isEmpty()) { return; }
        $top = $byTitle->first();
        $verified = (int) $top->n >= 2
            || $this->contributions()->where('title', $top->title)
                ->whereHas('user', fn ($q) => $q->where('trusted', true))->exists();

        if ($this->status === self::VERIFIED) {
            if ($verified && $top->title !== $this->title) { $this->update(['title' => $top->title]); }
        } else {
            $this->update(['title' => $top->title, 'status' => $verified ? self::VERIFIED : self::UNVERIFIED]);
        }
    }

    /** Distinct operators who've contributed a name (drives the "N sources" badge + the flag threshold). */
    public function contributorCount(): int { return (int) $this->contributions()->distinct('user_id')->count('user_id'); }

    /** Hide a name the community disputes: flags ≥ contributors and ≥2. Owner-locked names are exempt
     *  (only the owner touches those). Hidden portals drop out of every auto-naming read. */
    public function recomputeFlagStatus(): void {
        if ($this->status === self::OWNER_LOCKED) { return; }
        $flags = $this->flags()->count();
        if ($flags >= 2 && $flags >= $this->contributorCount() && $this->status !== self::HIDDEN) {
            $this->update(['status' => self::HIDDEN]);
        }
    }

    public function scopeSearch(Builder $q, ?string $t): Builder { $t=trim((string)$t); return $t!==''?$q->where('title','like','%'.$t.'%'):$q; }
    public function scopeRegion(Builder $q, ?string $r): Builder { $r=trim((string)$r); return $r!==''?$q->where('region',$r):$q; }
    /** Exclude flagged-hidden portals from any read that auto-populates an op. */
    public function scopeVisible(Builder $q): Builder { return $q->where('status', '!=', self::HIDDEN); }
    /** Catalog portals near a coordinate, nearest first — for naming map-drops / pasted Intel links.
     *  Hidden portals are dropped, and verified/owner-locked names rank ahead of unverified ones. */
    public function scopeNearestTo(Builder $q, float $lat, float $lng, float $eps = 0.0005): Builder {
        // squared euclidean distance without POW() (not guaranteed compiled into SQLite); the whereBetween box already narrows it
        return $q->visible()
            ->whereBetween('lat', [$lat - $eps, $lat + $eps])->whereBetween('lng', [$lng - $eps, $lng + $eps])
            ->orderByRaw("CASE WHEN status = '".self::UNVERIFIED."' THEN 1 ELSE 0 END")
            ->orderByRaw('(lat - ?) * (lat - ?) + (lng - ?) * (lng - ?)', [$lat, $lat, $lng, $lng]);
    }
}
