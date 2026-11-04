<?php
namespace Tests\Feature;
use App\Models\Op;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class DmTest extends TestCase {
    use RefreshDatabase;
    public function test_participants_can_dm_each_other(): void {
        $a = $this->mkUser(['google_id'=>'a','callsign'=>'A','faction'=>'ENL']);
        $b = $this->mkUser(['google_id'=>'b','callsign'=>'B','faction'=>'ENL']);
        $op = Op::create(['owner_id'=>$a->id,'name'=>'Op','type'=>'any_order','status'=>'active','join_token'=>'T']);
        $op->participants()->create(['user_id'=>$a->id,'role'=>'operative']);
        $op->participants()->create(['user_id'=>$b->id,'role'=>'agent']);
        $this->actingAs($a)->postJson("/ops/{$op->public_id}/dm/{$b->id}", ['body'=>'meet at anchor'])->assertOk()->assertJsonPath('mine',true);
        $this->actingAs($b)->getJson("/ops/{$op->public_id}/dm/{$a->id}")->assertOk()->assertJsonCount(1)->assertJsonPath('0.body','meet at anchor')->assertJsonPath('0.mine',false);
    }
    public function test_outsider_cannot_dm(): void {
        $a = $this->mkUser(['google_id'=>'a2','callsign'=>'A','faction'=>'ENL']);
        $b = $this->mkUser(['google_id'=>'b2','callsign'=>'B','faction'=>'ENL']);
        $x = $this->mkUser(['google_id'=>'x2','callsign'=>'X','faction'=>'RES']);
        $op = Op::create(['owner_id'=>$a->id,'name'=>'Op','type'=>'any_order','status'=>'active','join_token'=>'T2']);
        $op->participants()->create(['user_id'=>$a->id,'role'=>'operative']);
        $op->participants()->create(['user_id'=>$b->id,'role'=>'agent']);
        $this->actingAs($x)->postJson("/ops/{$op->public_id}/dm/{$b->id}", ['body'=>'hi'])->assertNotFound();
    }
}
