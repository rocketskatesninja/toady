<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
#[Fillable(['op_id','seq','role','guid','keys_needed','title','lat','lng','image','gate_pin','access_notes','parking','hours','hazards'])]
class OpWaypoint extends Model {
    public const ROLES = ['anchor', 'spine', 'target', 'waypoint'];
    protected function casts(): array { return ['lat'=>'float','lng'=>'float']; }
    public function op(): BelongsTo { return $this->belongsTo(Op::class); }
}
