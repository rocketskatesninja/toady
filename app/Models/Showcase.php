<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A curated "ops built with toady" gallery entry — story + up to 3 photos, optionally tagging registered agents. */
#[Fillable(['title', 'story', 'credit', 'images', 'tagged_ids', 'published'])]
class Showcase extends Model
{
    protected function casts(): array
    {
        return ['images' => 'array', 'tagged_ids' => 'array', 'published' => 'boolean'];
    }
}
