<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
#[Fillable(['op_id','sender_id','recipient_id','body','read_at'])]
class DirectMessage extends Model {
    protected function casts(): array { return ['read_at'=>'datetime']; }
}
