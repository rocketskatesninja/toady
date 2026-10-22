<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('op_participants', function (Blueprint $table) {
            $table->string('color', 7)->nullable(); // operator-assigned per-op colour (#rrggbb) — agent's map beacon/route + avatar ring
        });
    }

    public function down(): void
    {
        Schema::table('op_participants', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
