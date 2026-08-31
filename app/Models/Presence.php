<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
#[Fillable(['op_id','user_id','sharing','lat','lng','accuracy','last_seen'])]
class Presence extends Model {
    protected $table = 'op_presence';
    public const STALE_SECONDS = 120;
    protected function casts(): array { return ['sharing'=>'boolean','lat'=>'float','lng'=>'float','last_seen'=>'datetime']; }
    public function op(): BelongsTo { return $this->belongsTo(Op::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function isFresh(): bool { return $this->sharing && $this->last_seen && $this->last_seen->gt(now()->subSeconds(self::STALE_SECONDS)); }
}
