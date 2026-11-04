<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Niantic portal photo URL (lh3.googleusercontent.com) harvested from IITC — carried from the
        // master catalog onto an op's waypoints so the Waypoints card can show the portal's photo.
        Schema::table('master_portals', fn (Blueprint $t) => $t->string('image')->nullable());
        Schema::table('op_waypoints', fn (Blueprint $t) => $t->string('image')->nullable());
    }

    public function down(): void
    {
        Schema::table('master_portals', fn (Blueprint $t) => $t->dropColumn('image'));
        Schema::table('op_waypoints', fn (Blueprint $t) => $t->dropColumn('image'));
    }
};
