<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // ops gain a "complete" status (active → complete once the op is over). SQLite stores the enum as a
    // CHECK constraint, so this rebuilds the column to widen the allowed set. We redefine `type` too —
    // Laravel's SQLite rebuild only keeps CHECKs for columns it's explicitly given, so this preserves it.
    public function up(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            $table->enum('status', ['planning', 'active', 'complete', 'closed'])->default('planning')->change();
            $table->enum('type', ['visible', 'hidden', 'any_order'])->default('any_order')->change();
        });
    }

    public function down(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            $table->enum('status', ['planning', 'active', 'closed'])->default('planning')->change();
            $table->enum('type', ['visible', 'hidden', 'any_order'])->default('any_order')->change();
        });
    }
};
