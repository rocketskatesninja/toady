<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_id', 'actor_label', 'action', 'summary'])]
class AuditLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null; // created_at only

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Admin action trail — keep a year for accountability, then prune. Run via model:prune. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subYear());
    }
}
