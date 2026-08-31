<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // email/password sign-up (nullable: Google users have no password)
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
        });

        // per-op bans: a banned user cannot rejoin via the invite link or be re-added
        Schema::create('op_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('op_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('banned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['op_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('op_bans');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
