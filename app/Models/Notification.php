<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'op_id', 'type', 'title', 'body', 'url'])]
class Notification extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null; // created-once feed rows; never updated except read_at

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /** Feed rows are ephemeral alerts — the UI only shows the latest 30. Drop anything older than 60 days
     *  (op-scoped ones already purge on op close; this bounds the durable non-op ones). Run via model:prune. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(60));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function op(): BelongsTo
    {
        return $this->belongsTo(Op::class);
    }
}
