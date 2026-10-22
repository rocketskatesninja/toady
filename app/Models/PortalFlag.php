<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A dispute that a catalog portal's name is wrong (one row per user per portal). */
#[Fillable(['master_portal_id', 'user_id'])]
class PortalFlag extends Model
{
    public function portal(): BelongsTo
    {
        return $this->belongsTo(MasterPortal::class, 'master_portal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
