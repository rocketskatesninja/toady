<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A directive always sits on a portal: op_waypoint_id becomes required and deleting the portal deletes its
    // directives (it used to null the link, so the app deleted them by hand). Directives with no portal are removed,
    // and so are the stored undo points, which could otherwise restore one.
    public function up(): void
    {
        DB::table('op_steps')->whereNull('op_waypoint_id')->delete();
        DB::table('op_undo_snapshots')->delete();
        $this->relink(nullable: false);
    }

    public function down(): void
    {
        $this->relink(nullable: true);
    }

    private function relink(bool $nullable): void
    {
        Schema::table('op_steps', fn (Blueprint $t) => $t->dropForeign(['op_waypoint_id']));
        Schema::table('op_steps', function (Blueprint $t) use ($nullable) {
            $t->unsignedBigInteger('op_waypoint_id')->nullable($nullable)->change();
            $fk = $t->foreign('op_waypoint_id')->references('id')->on('op_waypoints');
            $nullable ? $fk->nullOnDelete() : $fk->cascadeOnDelete();
        });
    }
};
