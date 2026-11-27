<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// remember the sender's broadcast header + signature on their account (across devices)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mail_header')->nullable()->after('email_opt_out');
            $table->text('mail_signature')->nullable()->after('mail_header');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mail_header', 'mail_signature']);
        });
    }
};
