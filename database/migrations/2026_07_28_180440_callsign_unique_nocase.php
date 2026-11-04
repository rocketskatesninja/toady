<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rebuild the callsign unique index as case-insensitive (COLLATE NOCASE), so "Vector" and "vector"
     * can't both exist. Matches Ingress (codenames are unique regardless of case) and blocks case-variant
     * impersonation. Validation already checks case-insensitively; this is the belt-and-braces DB guarantee.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['callsign']); // drops users_callsign_unique (default BINARY)
        });
        DB::statement('CREATE UNIQUE INDEX users_callsign_unique ON users (callsign COLLATE NOCASE)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_callsign_unique');
        Schema::table('users', function (Blueprint $table) {
            $table->unique('callsign'); // back to the default case-sensitive index
        });
    }
};
