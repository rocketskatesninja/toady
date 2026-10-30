<?php

namespace App\Models;

use App\Support\Notifier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['owner_id', 'name', 'description', 'type', 'status', 'join_token', 'allow_export', 'goals', 'notes', 'shared_notes'])]
class Op extends Model
{
    /** Mission types (Niantic's vocabulary): any-order, or sequential with future waypoints visible/hidden. */
    public const TYPES = ['visible', 'hidden', 'any_order'];

    protected function casts(): array
    {
        return ['allow_export' => 'boolean'];
    }

    /** Route ops by their unguessable public_id, never the sequential integer PK (no enumeration). */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function booted(): void
    {
        // Every op gets an unguessable public URL id at creation (all paths: store, import, factory).
        static::creating(function (Op $op) {
            if (empty($op->public_id)) {
                $op->public_id = static::freshPublicId();
            }
        });

        // Defence-in-depth for the "all op data is permanently purged on close" promise: explicitly delete
        // every op-scoped row so purge never hinges solely on the DB FK cascade (which silently no-ops if
        // foreign-key enforcement is ever turned off). Belt-and-braces with the ON DELETE CASCADE keys.
        static::deleting(function (Op $op) {
            $op->participants()->delete();
            $op->waypoints()->delete();
            $op->steps()->delete();
            $op->keyHoldings()->delete();
            $op->presence()->delete();
            $op->messages()->delete();
            $op->bans()->delete();
            $op->undoSnapshots()->delete();
            DirectMessage::where('op_id', $op->id)->delete();
            Notification::where('op_id', $op->id)->delete(); // op-scoped only; nullable op_id (e.g. reports) is spared
        });
    }

    /** A new op in planning, owned by $owner, with the owner on its roster as operative (create + import). */
    public static function createFor(User $owner, array $attrs): self
    {
        $op = static::create([...$attrs, 'owner_id' => $owner->id, 'status' => 'planning', 'join_token' => static::freshToken()]);
        $op->participants()->create(['user_id' => $owner->id, 'role' => OpParticipant::ROLE_OPERATIVE]);

        return $op;
    }

    /** Live (not closed) ops the user is on, with only their own roster row loaded — so roleFor() / isOperative()
     *  resolve from memory instead of a query per op. */
    public function scopeLiveFor(Builder $query, User $user): Builder
    {
        return $query->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->where('status', '!=', 'closed')
            ->with(['participants' => fn ($q) => $q->where('user_id', $user->id)]);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(OpParticipant::class);
    }

    public function waypoints(): HasMany
    {
        return $this->hasMany(OpWaypoint::class)->orderBy('seq');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(OpStep::class)->orderBy('seq');
    }

    public function keyHoldings(): HasMany
    {
        return $this->hasMany(OpKeyHolding::class);
    }

    public function presence(): HasMany
    {
        return $this->hasMany(Presence::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OpMessage::class);
    }

    public function bans(): HasMany
    {
        return $this->hasMany(OpBan::class);
    }

    /** The op's undo stack — pre-edit plan snapshots, newest last. */
    public function undoSnapshots(): HasMany
    {
        return $this->hasMany(OpUndoSnapshot::class);
    }

    /** Add directives after the op's current last one, in the given order (phase "run" unless a row says
     *  otherwise) — every path that adds directives: manual, to-all, auto-fan, IITC import, templates. */
    public function appendSteps(iterable $rows): void
    {
        $seq = (int) $this->steps()->max('seq');
        foreach ($rows as $row) {
            $this->steps()->create(['phase' => 'run', ...$row, 'seq' => ++$seq]);
        }
    }

    /** Sequential missions (visible / hidden) run directive by directive; any-order ones don't. */
    public function isSequential(): bool
    {
        return $this->type !== 'any_order';
    }

    /** The op's directives in run order: by location (waypoint seq), then position within it. Sequential ops
     *  gate check-offs and pass the "your turn" baton along this order. */
    public function stepsInRunOrder(): Collection
    {
        $wpSeq = $this->waypoints()->pluck('seq', 'id');

        return $this->steps()->get()->sortBy(fn ($s) => [$wpSeq[$s->op_waypoint_id] ?? PHP_INT_MAX, $s->seq])->values();
    }

    public function isBanned(?User $user): bool
    {
        return $user && $this->bans()->where('user_id', $user->id)->exists();
    }

    /** A short, URL-safe code from an unambiguous alphabet, unique on the given column. */
    protected static function uniqueCode(string $column, int $len): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < $len; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where($column, $code)->exists());

        return $code;
    }

    /** A short, unguessable join token — the roster-grant credential (anyone with it can join). */
    public static function freshToken(int $len = 12): string
    {
        return static::uniqueCode('join_token', $len);
    }

    /** The op's unguessable public URL id — its canonical address (still participation-gated; not a grant). */
    public static function freshPublicId(int $len = 10): string
    {
        return static::uniqueCode('public_id', $len);
    }

    public function roleFor(?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        // reuse the already-loaded participants (e.g. OpController::show) instead of re-querying
        if ($this->relationLoaded('participants')) {
            return optional($this->participants->firstWhere('user_id', $user->id))->role;
        }

        return $this->participants()->where('user_id', $user->id)->value('role');
    }

    public function isOperative(?User $user): bool
    {
        return $user && ($this->owner_id === $user->id || $this->roleFor($user) === OpParticipant::ROLE_OPERATIVE);
    }

    /** The operative users to notify about op events (owner is an operative participant). */
    public function operativeRecipients(): Collection
    {
        return $this->participants()->where('role', OpParticipant::ROLE_OPERATIVE)->with('user')->get()
            ->map->user->filter()->values();
    }

    /**
     * The plan a viewer may see — hidden-op redaction in ONE place, so the op page, export, map overlay, and AI
     * snapshot can't drift and leak a future plan. Sequential "hidden" ops show agents only up to the current
     * waypoint; everything past it is redacted server-side. Operatives always see the full plan.
     *
     * @return array{0: Collection, 1: Collection, 2: ?Collection} [waypoints, steps, the visible waypoint ids —
     *                                                             null when nothing is redacted]
     */
    public function visiblePlan(bool $viewerIsOperative): array
    {
        $waypoints = $this->waypoints;
        $steps = $this->steps;
        if ($this->type !== 'hidden' || $viewerIsOperative || ($visibleIds = static::visibleWaypointIds($waypoints, $steps)) === null) {
            return [$waypoints, $steps, null];
        }

        return [
            $waypoints->filter(fn ($w) => $visibleIds->contains($w->id))->values(),
            $steps->filter(fn ($s) => $s->op_waypoint_id === null || $visibleIds->contains($s->op_waypoint_id))->values(),
            $visibleIds,
        ];
    }

    /** Waypoints an agent may see: everything up to and including the "front" — the first waypoint (by seq) that
     *  still has an unfinished directive. Null when there's no front (plan complete, or no directives yet). */
    private static function visibleWaypointIds(Collection $waypoints, Collection $steps): ?Collection
    {
        $front = $waypoints->sortBy('seq')->first(
            fn ($w) => $steps->where('op_waypoint_id', $w->id)->contains(fn ($s) => ! $s->done)
        );
        if (! $front) {
            return null;
        }

        return $waypoints->filter(fn ($w) => $w->seq <= $front->seq)->pluck('id');
    }

    /** Push "🏁 op complete" to every participant except the user who triggered it (one place for title/link/tag). */
    public function notifyComplete(int $exceptUserId, string $body): void
    {
        $this->participants()->where('user_id', '!=', $exceptUserId)->with('user')->get()
            ->each(fn ($p) => $p->user && Notifier::send($p->user, 'go', "🏁 {$this->name} complete", $body, "/ops/{$this->public_id}", $this->id, tag: "op-{$this->id}-done"));
    }
}
