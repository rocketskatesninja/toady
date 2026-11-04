<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a hack timer next runs out (its cooldown, or its burnout window) — kept up to date on every save,
 * and what toady:hack-alerts watches to push "ready" to the agent's phone. Null when nothing is pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hack_timers', function (Blueprint $table) {
            $table->timestamp('alert_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('hack_timers', function (Blueprint $table) {
            $table->dropIndex(['alert_at']);
            $table->dropColumn('alert_at');
        });
    }
};
