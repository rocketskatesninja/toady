<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A curated "ops built with toady" gallery entry — story + up to 5 photos, optionally tagging registered agents. */
#[Fillable(['title', 'story', 'credit', 'images', 'tagged_ids', 'published'])]
class Showcase extends Model
{
    protected function casts(): array
    {
        return ['images' => 'array', 'tagged_ids' => 'array', 'published' => 'boolean'];
    }

    /** Is the public /showcase page switched on? (Admin toggle; on by default.) */
    public static function enabled(): bool
    {
        return (bool) Setting::get('showcase_enabled', true);
    }

    /** Streaming URLs for the entry's photos (served from the private disk). */
    public function imageUrls(): array
    {
        return collect($this->images ?? [])->keys()->map(fn ($i) => route('showcase.image', ['showcase' => $this->id, 'index' => $i]))->all();
    }
}
