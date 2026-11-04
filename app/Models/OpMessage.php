<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
#[Fillable(['op_id','user_id','body'])]
class OpMessage extends Model {
    public function op(): BelongsTo { return $this->belongsTo(Op::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
