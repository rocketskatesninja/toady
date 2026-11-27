<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crowd-sourced catalog: operator-named portals flow back to master_portals as contributions;
 * a name becomes "verified" by consensus (2+ independent operators, or one trusted contributor).
 * Flags let anyone dispute a bad name. See app/Support/CatalogContributor.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // one vote per operator per portal (a rename updates their row)
        Schema::create('portal_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_portal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->timestamps();
            $table->unique(['master_portal_id', 'user_id']);
            $table->index('user_id'); // rate-limit counting
        });

        // one flag per user per portal
        Schema::create('portal_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_portal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['master_portal_id', 'user_id']);
        });

        Schema::table('master_portals', function (Blueprint $table) {
            // unverified | verified | owner_locked | hidden
            $table->string('status', 16)->default('verified')->after('source');
            $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
        });
        // Existing rows are the trusted seed: owner-entered (manual) become locked, the rest verified.
        DB::table('master_portals')->where('source', 'manual')->update(['status' => 'owner_locked']);

        // capture the portal GUID on a waypoint (from a scanner Share link or IITC) so contributions
        // match the authoritative id, not just coordinates
        Schema::table('op_waypoints', function (Blueprint $table) {
            $table->string('guid')->nullable()->after('role');
        });

        // owner-granted: a trusted contributor's single submission auto-verifies. NOT mass-assignable.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('trusted')->default(false)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('trusted'));
        Schema::table('op_waypoints', fn (Blueprint $t) => $t->dropColumn('guid'));
        Schema::table('master_portals', function (Blueprint $t) {
            $t->dropConstrainedForeignId('created_by');
            $t->dropColumn('status');
        });
        Schema::dropIfExists('portal_flags');
        Schema::dropIfExists('portal_contributions');
    }
};
