<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hack timers — an agent's own cooldown/burnout clock per portal, plus the keys they farmed there.
 * Owned by the AGENT, not the op: closing an op (a hard delete) only unlinks its timers (nullOnDelete),
 * so a burnout that's still running survives. Standalone timers have no op or portal at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hack_timers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('op_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('op_waypoint_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 80)->nullable();  // op: the portal's name (kept after the op closes) · standalone: your nickname, else "Timer N"
            $table->boolean('friendly')->default(false); // own-faction portal → 180 s cooldown, else 300 s
            $table->json('mods');                        // the portal's 4 mod slots: "heat_sink:rare" | "multi_hack:very_rare" | null
            $table->json('hacks');                       // ISO times of the hacks in the current burnout window
            $table->unsignedInteger('keys')->default(0); // keys farmed on this timer
            $table->timestamps();                        // updated_at drives the 14-day idle prune
            $table->index(['user_id', 'op_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hack_timers');
    }
};
