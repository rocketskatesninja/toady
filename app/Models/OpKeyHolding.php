<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['op_id', 'op_waypoint_id', 'user_id', 'qty'])]
class OpKeyHolding extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
