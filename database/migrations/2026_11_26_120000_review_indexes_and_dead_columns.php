<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * From the Nov 2026 site review: indexes the every-3-seconds op poll and the cascade deletes were missing,
 * two indexes nothing could use, and three columns no code reads or writes any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        // read on every poll / every push, or scanned once per deleted parent row
        Schema::table('op_tasks', fn (Blueprint $t) => $t->index('op_id'));
        Schema::table('op_participants', fn (Blueprint $t) => $t->index('user_id'));
        Schema::table('op_key_holdings', fn (Blueprint $t) => $t->index('op_waypoint_id'));
        Schema::table('notifications', fn (Blueprint $t) => $t->index('op_id'));
        Schema::table('hack_timers', function (Blueprint $t) {
            $t->index('op_id');
            $t->index('op_waypoint_id');
        });

        // one browser endpoint = one row (the subscribe path already enforces it; keep the newest of any strays)
        DB::statement('DELETE FROM push_subscriptions WHERE id NOT IN (SELECT MAX(id) FROM push_subscriptions GROUP BY endpoint)');
        Schema::table('push_subscriptions', function (Blueprint $t) {
            $t->index('user_id');
            $t->unique('endpoint');
        });

        // nothing filters directives by phase any more — the poll orders by seq within an op
        Schema::table('op_steps', function (Blueprint $t) {
            $t->dropIndex('op_steps_op_id_phase_seq_index');
            $t->index(['op_id', 'seq']);
            $t->index('op_waypoint_id');
        });

        // catalog search is LIKE '%q%', which can't use an index on title
        Schema::table('master_portals', fn (Blueprint $t) => $t->dropIndex('master_portals_title_index'));

        // left over from the dropped "ephemeral account" model, a removed profile toggle, and a read-receipt
        // that was never built
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['saved', 'show_reference']));
        Schema::table('direct_messages', fn (Blueprint $t) => $t->dropColumn('read_at'));
    }

    public function down(): void
    {
        Schema::table('direct_messages', fn (Blueprint $t) => $t->timestamp('read_at')->nullable());
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('saved')->default(false);
            $t->boolean('show_reference')->default(true);
        });
        Schema::table('master_portals', fn (Blueprint $t) => $t->index('title'));
        Schema::table('op_steps', function (Blueprint $t) {
            $t->dropIndex(['op_waypoint_id']);
            $t->dropIndex(['op_id', 'seq']);
            $t->index(['op_id', 'phase', 'seq']);
        });
        Schema::table('push_subscriptions', function (Blueprint $t) {
            $t->dropUnique(['endpoint']);
            $t->dropIndex(['user_id']);
        });
        Schema::table('hack_timers', function (Blueprint $t) {
            $t->dropIndex(['op_waypoint_id']);
            $t->dropIndex(['op_id']);
        });
        Schema::table('notifications', fn (Blueprint $t) => $t->dropIndex(['op_id']));
        Schema::table('op_key_holdings', fn (Blueprint $t) => $t->dropIndex(['op_waypoint_id']));
        Schema::table('op_participants', fn (Blueprint $t) => $t->dropIndex(['user_id']));
        Schema::table('op_tasks', fn (Blueprint $t) => $t->dropIndex(['op_id']));
    }
};
