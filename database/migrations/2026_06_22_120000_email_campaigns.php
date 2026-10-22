<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// marketing/broadcast email: per-user opt-out + an audit row per campaign sent
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_opt_out')->default(false)->after('notify_prefs');
        });

        Schema::create('mail_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->string('header')->nullable();
            $table->text('body');
            $table->text('signature')->nullable();
            $table->string('format', 8)->default('html'); // html | text
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_campaigns');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_opt_out');
        });
    }
};
