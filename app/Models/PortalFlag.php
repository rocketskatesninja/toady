<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A dispute that a catalog portal's name is wrong (one row per user per portal). */
#[Fillable(['master_portal_id', 'user_id'])]
class PortalFlag extends Model {}
