<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            $table->text('shared_notes')->nullable(); // operator-written notes shared with the whole op (all read)
        });
    }

    public function down(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            $table->dropColumn('shared_notes');
        });
    }
};
