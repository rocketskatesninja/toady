<?php

namespace App\Models;

use App\Support\Mechanics;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One agent's hack clock on one portal (see the create_hack_timers_table migration). The server owns the
 * math (Mechanics::cooldownSeconds / hackLimit) and ships the results; the browser only counts down.
 */
#[Fillable(['user_id', 'op_id', 'op_waypoint_id', 'label', 'friendly', 'mods', 'hacks', 'keys'])]
class HackTimer extends Model
{
    use MassPrunable;

    /** A timer nobody has touched in this many days is pruned nightly (model:prune). */
    public const IDLE_DAYS = 14;

    /** An alert found more than this many seconds past due (the watcher was down) is dropped, not sent late. */
    public const ALERT_GRACE = 120;

    protected $attributes = ['mods' => '[null,null,null,null]', 'hacks' => '[]', 'keys' => 0, 'friendly' => false];

    protected function casts(): array
    {
        return ['friendly' => 'boolean', 'mods' => 'array', 'hacks' => 'array', 'keys' => 'integer', 'alert_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // keep "when does this run out" current — it's what toady:hack-alerts watches. Only the clock's inputs move it,
        // so a key tap or a rename in the seconds before a due alert is sent can't drop that alert.
        static::saving(function (self $timer) {
            if ($timer->isDirty(['hacks', 'mods', 'friendly'])) {
                $timer->alert_at = $timer->runsOutAt();
            }
        });
    }

    /** Timers whose alert is due by $at (a null alert_at never matches). */
    public function scopeAlertDueBy(Builder $query, Carbon $at): Builder
    {
        return $query->where('alert_at', '<=', $at);
    }

    /** What a mod slot may hold besides empty (null): "heat_sink:rare" etc. — built from the rarities the math knows. */
    public static function modKeys(): array
    {
        return [
            ...array_map(fn ($r) => "heat_sink:$r", array_keys(Mechanics::HEAT_SINK)),
            ...array_map(fn ($r) => "multi_hack:$r", array_keys(Mechanics::MULTI_HACK)),
        ];
    }

    /**
     * Put a mod in one of the 4 slots (null empties it). One more Heat Sink than before means you installed it —
     * the game resets the installer's cooldown + burnout, so the clock does too. Swapping a Heat Sink's rarity,
     * or removing a mod, is a correction and leaves the clock alone.
     */
    public function setMod(int $slot, ?string $mod): void
    {
        $heatSinks = count($this->modsOf('heat_sink'));
        $this->mods = array_replace($this->mods, [$slot => $mod]);
        if (count($this->modsOf('heat_sink')) > $heatSinks) {
            $this->hacks = [];
        }
        $this->save();
    }

    /** The rarities of the installed mods of one type, e.g. modsOf('heat_sink') → ['very_rare', 'rare']. */
    public function modsOf(string $type): array
    {
        return array_values(array_map(
            fn ($m) => substr($m, strlen($type) + 1),
            array_filter($this->mods, fn ($m) => is_string($m) && str_starts_with($m, $type.':')),
        ));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function op(): BelongsTo
    {
        return $this->belongsTo(Op::class);
    }

    /** A linked portal's live name; the snapshot covers a renamed/deleted portal or a closed op. */
    public function name(): string
    {
        return $this->waypoint?->title ?: (string) $this->label;
    }

    public function waypoint(): BelongsTo
    {
        return $this->belongsTo(OpWaypoint::class, 'op_waypoint_id');
    }

    public function prunable(): Builder
    {
        return static::where('updated_at', '<', now()->subDays(self::IDLE_DAYS));
    }

    /** When the burnout window closes — 4 h after its first hack (null before any hack). */
    public function windowEndsAt(): ?Carbon
    {
        return isset($this->hacks[0]) ? Carbon::parse($this->hacks[0])->addHours(Mechanics::BURNOUT_HOURS) : null;
    }

    /** The hacks still inside the burnout window — once it closes, the agent starts fresh. */
    public function windowHacks(): array
    {
        return $this->windowEndsAt()?->isPast() ? [] : $this->hacks;
    }

    public function cooldownSeconds(): int
    {
        return Mechanics::cooldownSeconds($this->friendly, $this->modsOf('heat_sink'));
    }

    public function hackLimit(): int
    {
        return Mechanics::hackLimit($this->modsOf('multi_hack'));
    }

    /** When the current wait ends — the burnout window closing if you're out of hacks, else the cooldown. Null when idle. */
    public function runsOutAt(): ?Carbon
    {
        $hacks = $this->windowHacks();
        if (! $hacks) {
            return null;
        }
        $at = count($hacks) >= $this->hackLimit()
            ? $this->windowEndsAt()
            : Carbon::parse(end($hacks))->addSeconds($this->cooldownSeconds());

        return $at->isFuture() ? $at : null;
    }

    /** The phone notification for when this timer runs out (toady:hack-alerts sends it; same words as the in-page toast). */
    public function alertPayload(): array
    {
        return [
            'title' => '⚡ '.$this->name().' — ready',
            // the window closed → a fresh set of hacks; otherwise a cooldown ran out
            'body' => $this->windowHacks() ? 'Cooldown’s over: hack away.' : 'Burnout’s over: a fresh set of hacks.',
            'url' => $this->op ? '/ops/'.$this->op->public_id.'?view=hacks' : '/hacks',
            'tag' => 'hack-'.$this->id,
        ];
    }

    public function toPayload(): array
    {
        $hacks = $this->windowHacks();

        return [
            'id' => $this->id,
            'label' => $this->name(),
            'op_waypoint_id' => $this->op_waypoint_id,
            'op' => $this->op ? ['id' => $this->op->public_id, 'name' => $this->op->name] : null,
            'friendly' => $this->friendly,
            'mods' => $this->mods,
            'keys' => $this->keys,
            'hacks' => $hacks,
            'cooldown_s' => $this->cooldownSeconds(),
            'hack_limit' => $this->hackLimit(),
            'window_ends_at' => $hacks ? $this->windowEndsAt()->toIso8601String() : null,
        ];
    }
}
