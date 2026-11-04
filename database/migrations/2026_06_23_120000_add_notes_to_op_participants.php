<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('op_participants', function (Blueprint $table) {
            $table->text('notes')->nullable(); // each participant's private per-op scratchpad (purged with the op)
        });
    }

    public function down(): void
    {
        Schema::table('op_participants', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
