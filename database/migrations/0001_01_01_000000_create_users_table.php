<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Identity is Google OAuth only. Most users are ephemeral (purged when they quit an op)
        // unless `saved` is set. Contact fields are all optional, visible to the whole op.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('google_id')->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->string('callsign')->nullable()->unique();
            $table->enum('faction', ['ENL', 'RES'])->nullable();
            $table->boolean('is_owner')->default(false);      // catalog curator (you)
            $table->boolean('saved')->default(false);         // opt-in persistence
            // optional contact info
            $table->string('phone')->nullable();
            $table->string('telegram')->nullable();
            $table->string('preferred_contact')->nullable();
            $table->string('emergency_contact')->nullable();
            // prefs
            $table->boolean('show_reference')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
