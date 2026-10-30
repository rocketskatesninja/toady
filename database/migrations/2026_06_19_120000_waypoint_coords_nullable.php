<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Generic locations have no portal/coords yet — you set them later.
    public function up(): void
    {
        Schema::table('op_waypoints', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->change();
            $table->decimal('lng', 10, 7)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('op_waypoints', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable(false)->change();
            $table->decimal('lng', 10, 7)->nullable(false)->change();
        });
    }
};
