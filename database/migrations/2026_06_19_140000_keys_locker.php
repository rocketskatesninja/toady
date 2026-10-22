<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // how many keys of this portal the plan needs (= inbound link count)
        Schema::table('op_waypoints', function (Blueprint $table) {
            $table->unsignedInteger('keys_needed')->default(0)->after('role');
        });

        // per-agent self-reported key holdings
        Schema::create('op_key_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('op_id')->constrained()->cascadeOnDelete();
            $table->foreignId('op_waypoint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty')->default(0);
            $table->timestamps();
            $table->unique(['op_id', 'op_waypoint_id', 'user_id']);
        });

        // the old per-op aggregate key table was never used
        Schema::dropIfExists('op_keys');
    }

    public function down(): void
    {
        Schema::dropIfExists('op_key_holdings');
        Schema::table('op_waypoints', fn (Blueprint $t) => $t->dropColumn('keys_needed'));
    }
};
