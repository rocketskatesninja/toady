<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable pre-edit snapshot of an op's editable plan (op_waypoints + op_steps + op_key_holdings),
 * pushed by CapturesOpUndo before a covered planning edit. The undo stack pops the newest first. Rows
 * are never updated (created_at only) and are purged with the op.
 */
#[Fillable(['op_id', 'data'])]
class OpUndoSnapshot extends Model
{
    public const UPDATED_AT = null; // immutable — only created_at is managed

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function op(): BelongsTo
    {
        return $this->belongsTo(Op::class);
    }
}
