<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A directive is now "objective (+ optional comment)" — so the comment text is optional.
    public function up(): void
    {
        Schema::table('op_steps', function (Blueprint $table) {
            $table->string('text')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('op_steps', function (Blueprint $table) {
            $table->string('text')->nullable(false)->change();
        });
    }
};
