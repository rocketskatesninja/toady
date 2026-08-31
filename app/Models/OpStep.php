<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
#[Fillable(['op_id','op_waypoint_id','phase','seq','text','action','assignee_id','resos','mods','qty','links','notes','done','done_by','done_at'])]
class OpStep extends Model {
    protected function casts(): array { return ['links'=>'array','qty'=>'integer','done'=>'boolean','done_at'=>'datetime']; }
    public function op(): BelongsTo { return $this->belongsTo(Op::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assignee_id'); }
}
