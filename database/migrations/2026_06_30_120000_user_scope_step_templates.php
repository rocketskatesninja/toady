<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Directive templates become USER-owned so an operator can reuse them across every op they run, instead of
// being scoped to (and purged with) a single op. Backfill ownership from each template's originating op,
// then drop the op link.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('op_step_templates', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // each existing template was op-scoped → hand it to that op's owner (the operator who made it)
        DB::table('op_step_templates')->update([
            'user_id' => DB::raw('(SELECT owner_id FROM ops WHERE ops.id = op_step_templates.op_id)'),
        ]);
        // belt-and-braces: anything still unowned (its op is gone) can't be reused — drop it
        DB::table('op_step_templates')->whereNull('user_id')->delete();

        Schema::table('op_step_templates', function (Blueprint $table) {
            $table->dropForeign(['op_id']);
            $table->dropColumn('op_id');
        });
    }

    public function down(): void
    {
        Schema::table('op_step_templates', function (Blueprint $table) {
            $table->foreignId('op_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        Schema::table('op_step_templates', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
