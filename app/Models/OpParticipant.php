<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['op_id', 'user_id', 'role', 'color'])]
class OpParticipant extends Model
{
    public const ROLE_OPERATIVE = 'operative';
    public const ROLE_AGENT = 'agent';

    public function op(): BelongsTo
    {
        return $this->belongsTo(Op::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
