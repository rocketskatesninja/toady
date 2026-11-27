<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'name', 'steps'])]
class OpStepTemplate extends Model
{
    protected function casts(): array
    {
        return ['steps' => 'array'];
    }
}
