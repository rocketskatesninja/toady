<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// a quantity for count-based directives (e.g. "farm keys" → how many)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('op_steps', function (Blueprint $table) {
            $table->unsignedSmallInteger('qty')->nullable()->after('mods');
        });
    }

    public function down(): void
    {
        Schema::table('op_steps', function (Blueprint $table) {
            $table->dropColumn('qty');
        });
    }
};
