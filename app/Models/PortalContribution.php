<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One operator's proposed name for a catalog portal (one row per user per portal). */
#[Fillable(['master_portal_id', 'user_id', 'title'])]
class PortalContribution extends Model
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
